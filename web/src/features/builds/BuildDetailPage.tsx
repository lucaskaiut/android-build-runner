import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router'
import { AlertTriangle, Download, FileArchive, XOctagon } from 'lucide-react'
import { BuildStatusBadge } from '@/components/StatusBadge'
import {
  Button,
  buttonClass,
  Card,
  ConfirmDialog,
  ErrorState,
  LoadingBlock,
  MetaItem,
  MetaList,
  PageHeader,
  TableShell,
  Td,
  Th,
} from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage } from '@/lib/api'
import { formatBytes, formatDateTime, formatDuration, shortSha } from '@/lib/format'
import { LogViewer } from './LogViewer'
import type { Build } from '@/lib/types'

const activeStatuses = ['queued', 'preparing', 'running']

export function BuildDetailPage() {
  const params = useParams()
  const buildId = Number(params.id)
  const queryClient = useQueryClient()
  const toast = useToast()
  const [confirmCancel, setConfirmCancel] = useState(false)
  const [headerStatus, setHeaderStatus] = useState<string | null>(null)

  const buildQuery = useQuery({
    queryKey: ['build', buildId],
    queryFn: () => api.build(buildId),
    refetchInterval: (query) => {
      const status = query.state.data?.build.status ?? headerStatus
      return status && activeStatuses.includes(status) ? 3000 : false
    },
  })

  const artifactsQuery = useQuery({
    queryKey: ['build-artifacts', buildId],
    queryFn: () => api.buildArtifacts(buildId),
    enabled: buildQuery.data !== undefined,
  })

  const cancel = useMutation({
    mutationFn: () => api.cancelBuild(buildId),
    onSuccess: () => {
      setConfirmCancel(false)
      toast.success('Cancelamento solicitado')
      void queryClient.invalidateQueries({ queryKey: ['build', buildId] })
    },
    onError: (error) => toast.error('Falha ao cancelar', apiErrorMessage(error)),
  })

  if (buildQuery.isLoading) return <LoadingBlock />
  if (buildQuery.isError || !buildQuery.data) {
    return <ErrorState message={apiErrorMessage(buildQuery.error)} onRetry={() => buildQuery.refetch()} />
  }

  const build: Build = buildQuery.data.build
  const isActive = activeStatuses.includes(build.status)
  const artifacts = artifactsQuery.data?.artifacts ?? build.artifacts ?? []

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        breadcrumb={
          <>
            <Link to="/builds" className="focus-ring rounded-sm hover:text-fg">
              Builds
            </Link>
            <span aria-hidden>/</span>
            <span className="text-fg-muted">#{build.id}</span>
          </>
        }
        title={`Build #${build.id}`}
        badge={<BuildStatusBadge status={build.status} label={build.status_label} />}
        description={
          <span>
            {build.project ? (
              <Link to={`/projects/${build.project.id}`} className="focus-ring rounded-sm text-accent hover:underline">
                {build.project.name}
              </Link>
            ) : (
              '—'
            )}
            {build.profile ? ` · ${build.profile.name}` : ''} · {build.source_label}
            {build.runner ? ` · runner ${build.runner.name}` : ''}
          </span>
        }
        actions={
          isActive ? (
            <Button variant="danger" onClick={() => setConfirmCancel(true)}>
              <XOctagon className="size-4" aria-hidden /> Cancelar build
            </Button>
          ) : undefined
        }
      />

      {build.cancel_requested && isActive ? (
        <p className="flex items-start gap-2 rounded-control bg-warning-soft px-3 py-2.5 text-body text-warning">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          Cancelamento solicitado — aguardando o runner interromper a execução.
        </p>
      ) : null}

      {build.error_message ? (
        <p className="flex items-start gap-2 rounded-control bg-danger-soft px-3 py-2.5 text-body break-words text-danger">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          {build.error_message}
        </p>
      ) : null}

      <Card title="Execução">
        <MetaList className="sm:grid-cols-2 lg:grid-cols-4">
          <MetaItem label="Projeto">
            {build.project ? (
              <Link to={`/projects/${build.project.id}`} className="focus-ring rounded-sm text-accent hover:underline">
                {build.project.name}
              </Link>
            ) : (
              '—'
            )}
          </MetaItem>
          <MetaItem label="Perfil">{build.profile?.name ?? '—'}</MetaItem>
          <MetaItem label="Origem">{build.source_label}</MetaItem>
          <MetaItem label="Runner">{build.runner?.name ?? 'Aguardando runner'}</MetaItem>
          <MetaItem label="Branch" mono>
            {build.branch ?? '—'}
          </MetaItem>
          <MetaItem label="Commit" mono title={build.commit_sha ?? ''}>
            {build.commit_sha ?? 'resolvido no checkout'}
          </MetaItem>
          <MetaItem label="Código de saída" mono>
            {build.exit_code ?? '—'}
          </MetaItem>
          <MetaItem label="Duração">{formatDuration(build.duration_ms)}</MetaItem>
          <MetaItem label="Na fila em">{formatDateTime(build.queued_at)}</MetaItem>
          <MetaItem label="Início">{formatDateTime(build.started_at ?? build.claimed_at)}</MetaItem>
          <MetaItem label="Fim">{formatDateTime(build.finished_at)}</MetaItem>
        </MetaList>

        {build.parameters && Object.keys(build.parameters).length > 0 ? (
          <div className="mt-4 border-t border-line pt-3.5">
            <p className="text-meta text-fg-subtle">Parâmetros</p>
            <ul className="mt-1.5 flex flex-wrap gap-1.5">
              {Object.entries(build.parameters).map(([key, value]) => (
                <li key={key} className="rounded-control bg-inset px-2 py-1 font-mono text-micro text-fg-muted">
                  {key}={value}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </Card>

      <Card
        title={`Artefatos${artifacts.length > 0 ? ` · ${artifacts.length}` : ''}`}
        description="Arquivos disponibilizados pelo runner ao final da execução."
        bodyClassName="p-0"
      >
        {artifacts.length === 0 ? (
          <p className="px-4 py-5 text-center text-meta text-fg-subtle">
            {isActive ? 'Os artefatos aparecem quando a build terminar.' : 'Nenhum artefato registrado.'}
          </p>
        ) : (
          <TableShell>
            <thead>
              <tr>
                <Th>Arquivo</Th>
                <Th>Tipo</Th>
                <Th className="text-right">Tamanho</Th>
                <Th>SHA-256</Th>
                <Th className="w-24 text-right">Download</Th>
              </tr>
            </thead>
            <tbody>
              {artifacts.map((artifact) => (
                <tr key={artifact.id} className="transition-colors hover:bg-fill-hover/60">
                  <Td>
                    <div className="flex items-center gap-2">
                      <FileArchive className="size-4 shrink-0 text-fg-subtle" aria-hidden />
                      <span className="text-body">{artifact.original_name}</span>
                    </div>
                  </Td>
                  <Td className="text-meta uppercase text-fg-muted">{artifact.type}</Td>
                  <Td className="text-right text-meta tabular-nums text-fg-muted">
                    {formatBytes(artifact.size_bytes)}
                  </Td>
                  <Td className="font-mono text-micro text-fg-muted" title={artifact.sha256}>
                    {shortSha(artifact.sha256)}
                  </Td>
                  <Td className="text-right">
                    <a
                      href={artifact.download_path}
                      download
                      className={buttonClass({ variant: 'secondary', size: 'sm' })}
                    >
                      <Download className="size-3.5" aria-hidden /> Baixar
                    </a>
                  </Td>
                </tr>
              ))}
            </tbody>
          </TableShell>
        )}
      </Card>

      <Card
        title="Logs"
        description={
          isActive
            ? 'Saída transmitida em tempo real enquanto a build executa.'
            : 'Saída completa registrada pelo runner.'
        }
      >
        <LogViewer
          buildId={build.id}
          live={isActive}
          onStatus={(status) => setHeaderStatus(status.status)}
          onEnd={() => {
            void queryClient.invalidateQueries({ queryKey: ['build', buildId] })
            void queryClient.invalidateQueries({ queryKey: ['build-artifacts', buildId] })
          }}
        />
      </Card>

      <ConfirmDialog
        open={confirmCancel}
        title="Cancelar build"
        message="O runner será instruído a interromper o processo e seus subprocessos. Builds concluídas não podem ser canceladas."
        confirmLabel="Cancelar build"
        destructive
        loading={cancel.isPending}
        onClose={() => setConfirmCancel(false)}
        onConfirm={() => cancel.mutate()}
      />
    </div>
  )
}
