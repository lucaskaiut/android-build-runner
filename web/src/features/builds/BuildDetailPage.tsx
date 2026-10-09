import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { ArrowLeft, Download, FileArchive, XOctagon } from 'lucide-react'
import { BuildStatusBadge, RunnerStatusBadge } from '@/components/StatusBadge'
import { Button, Card, ConfirmDialog, ErrorState, LoadingBlock, TableShell, Td, Th } from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage } from '@/lib/api'
import { formatBytes, formatDateTime, formatDuration, shortSha } from '@/lib/format'
import { LogViewer } from './LogViewer'
import type { Build } from '@/lib/types'

const activeStatuses = ['queued', 'preparing', 'running']

export function BuildDetailPage() {
  const params = useParams()
  const buildId = Number(params.id)
  const navigate = useNavigate()
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
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/builds')} className="-ml-2 mb-1">
          <ArrowLeft className="size-3.5" /> Builds
        </Button>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold">Build #{build.id}</h1>
            <BuildStatusBadge status={build.status} label={build.status_label} />
            {build.runner ? <RunnerStatusBadge status="online" label={`runner: ${build.runner.name}`} /> : null}
          </div>
          {isActive ? (
            <Button variant="danger" size="sm" onClick={() => setConfirmCancel(true)}>
              <XOctagon className="size-3.5" /> Cancelar build
            </Button>
          ) : null}
        </div>
      </div>

      {build.cancel_requested && isActive ? (
        <p className="rounded-md bg-[var(--app-warning-soft)] px-3 py-2 text-xs text-[var(--app-warning)]">
          Cancelamento solicitado — aguardando o runner interromper a execução.
        </p>
      ) : null}

      {build.error_message ? (
        <p className="rounded-md bg-[var(--app-danger-soft)] px-3 py-2 text-xs break-words text-[var(--app-danger)]">
          {build.error_message}
        </p>
      ) : null}

      <Card title="Execução">
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Projeto</dt>
            <dd>
              <Link to={`/projects/${build.project?.id}`} className="text-[var(--app-accent)] hover:underline">
                {build.project?.name ?? '—'}
              </Link>
            </dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Perfil</dt>
            <dd>{build.profile?.name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Origem</dt>
            <dd>{build.source_label}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Runner</dt>
            <dd>{build.runner?.name ?? 'Aguardando runner'}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Branch</dt>
            <dd className="font-mono text-xs">{build.branch ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Commit</dt>
            <dd className="font-mono text-xs" title={build.commit_sha ?? ''}>
              {build.commit_sha ?? 'resolvido no checkout'}
            </dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Código de saída</dt>
            <dd className="font-mono text-xs">{build.exit_code ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Duração</dt>
            <dd>{formatDuration(build.duration_ms)}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Na fila em</dt>
            <dd className="text-xs">{formatDateTime(build.queued_at)}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Início</dt>
            <dd className="text-xs">{formatDateTime(build.started_at ?? build.claimed_at)}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Fim</dt>
            <dd className="text-xs">{formatDateTime(build.finished_at)}</dd>
          </div>
        </dl>

        {build.parameters && Object.keys(build.parameters).length > 0 ? (
          <div className="mt-4 border-t border-[var(--app-border)] pt-3">
            <p className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Parâmetros</p>
            <ul className="mt-1 flex flex-wrap gap-2">
              {Object.entries(build.parameters).map(([key, value]) => (
                <li key={key} className="rounded bg-[var(--app-surface-2)] px-2 py-1 font-mono text-[11px]">
                  {key}={value}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </Card>

      <Card
        title="Artefatos"
        description="Arquivos disponibilizados pelo runner ao final da execução."
        bodyClassName="p-0"
      >
        {artifacts.length === 0 ? (
          <p className="px-4 py-6 text-center text-xs text-[var(--app-fg-subtle)]">
            {isActive ? 'Os artefatos aparecem quando a build terminar.' : 'Nenhum artefato registrado.'}
          </p>
        ) : (
          <TableShell>
            <thead>
              <tr>
                <Th>Arquivo</Th>
                <Th>Tipo</Th>
                <Th>Tamanho</Th>
                <Th>SHA-256</Th>
                <Th className="w-28 text-right">Download</Th>
              </tr>
            </thead>
            <tbody>
              {artifacts.map((artifact) => (
                <tr key={artifact.id}>
                  <Td>
                    <div className="flex items-center gap-2">
                      <FileArchive className="size-4 text-[var(--app-fg-subtle)]" />
                      <span className="text-sm">{artifact.original_name}</span>
                    </div>
                  </Td>
                  <Td className="text-xs uppercase">{artifact.type}</Td>
                  <Td className="text-xs">{formatBytes(artifact.size_bytes)}</Td>
                  <Td className="font-mono text-[11px]" title={artifact.sha256}>
                    {shortSha(artifact.sha256)}
                  </Td>
                  <Td className="text-right">
                    <a
                      href={artifact.download_path}
                      className="inline-flex items-center gap-1.5 text-xs font-medium text-[var(--app-accent)] hover:underline"
                      download
                    >
                      <Download className="size-3.5" /> Baixar
                    </a>
                  </Td>
                </tr>
              ))}
            </tbody>
          </TableShell>
        )}
      </Card>

      <Card title="Logs em tempo real" bodyClassName="p-4">
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
