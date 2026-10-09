import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, KeyRound, Plus, RefreshCw, Trash2 } from 'lucide-react'
import {
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Field,
  IconButton,
  Input,
  LoadingBlock,
  Modal,
  Select,
  TableShell,
  Td,
  Th,
} from '@/components/ui'
import { WebhookStatusBadge } from '@/components/StatusBadge'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage } from '@/lib/api'
import { formatRelative, shortSha } from '@/lib/format'
import type { BranchTrigger, BuildProfile, Project } from '@/lib/types'

export function WebhookPanel({
  project,
  profiles,
  branchTriggers,
}: {
  project: Project
  profiles: BuildProfile[]
  branchTriggers: BranchTrigger[]
}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [secretModal, setSecretModal] = useState<string | null>(null)
  const [confirmRotate, setConfirmRotate] = useState(false)
  const [triggerToRemove, setTriggerToRemove] = useState<BranchTrigger | null>(null)
  const [newPattern, setNewPattern] = useState('')
  const [newProfileId, setNewProfileId] = useState('')

  const deliveries = useQuery({
    queryKey: ['webhook-deliveries', project.id],
    queryFn: () => api.webhookDeliveries(project.id, { per_page: 10 }),
    refetchInterval: 30000,
  })

  const webhookUrl = `${window.location.origin}${project.webhook_path}`

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['project', project.id] })
    void queryClient.invalidateQueries({ queryKey: ['projects'] })
  }

  const toggleAutomation = useMutation({
    mutationFn: (enabled: boolean) => api.updateProject(project.id, { webhook_enabled: enabled }),
    onSuccess: () => {
      invalidate()
      toast.success('Configuração do webhook atualizada')
    },
    onError: (error) => toast.error('Falha ao atualizar', apiErrorMessage(error)),
  })

  const rotate = useMutation({
    mutationFn: () => api.rotateWebhookSecret(project.id),
    onSuccess: (data) => {
      invalidate()
      setConfirmRotate(false)
      setSecretModal(data.webhook_secret)
    },
    onError: (error) => toast.error('Falha ao gerar segredo', apiErrorMessage(error)),
  })

  const addTrigger = useMutation({
    mutationFn: () =>
      api.createBranchTrigger(project.id, {
        build_profile_id: Number(newProfileId),
        branch_pattern: newPattern.trim(),
      }),
    onSuccess: () => {
      invalidate()
      setNewPattern('')
      toast.success('Gatilho adicionado')
    },
    onError: (error) => toast.error('Falha ao adicionar gatilho', apiErrorMessage(error)),
  })

  const removeTrigger = useMutation({
    mutationFn: (id: number) => api.deleteBranchTrigger(id),
    onSuccess: () => {
      invalidate()
      setTriggerToRemove(null)
      toast.success('Gatilho removido')
    },
    onError: (error) => toast.error('Falha ao remover', apiErrorMessage(error)),
  })

  const copy = async (value: string, label: string) => {
    try {
      await navigator.clipboard.writeText(value)
      toast.success(`${label} copiado`)
    } catch {
      toast.error('Não foi possível copiar')
    }
  }

  return (
    <Card
      title="Automação do GitHub"
      description="Executa builds automaticamente em push de branches monitoradas."
      actions={
        <label className="flex items-center gap-2 text-meta text-fg-muted">
          <input
            type="checkbox"
            className="size-3.5 accent-accent"
            checked={project.webhook_enabled}
            disabled={toggleAutomation.isPending}
            onChange={(event) => toggleAutomation.mutate(event.target.checked)}
          />
          Automação ativa
        </label>
      }
    >
      <div className="flex flex-col gap-5">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="min-w-0 rounded-control bg-inset px-3 py-2.5">
            <p className="text-meta text-fg-subtle">Payload URL</p>
            <div className="mt-1 flex items-center gap-1.5">
              <code className="min-w-0 flex-1 truncate text-meta text-fg" title={webhookUrl}>
                {webhookUrl}
              </code>
              <IconButton label="Copiar Payload URL" size="sm" onClick={() => copy(webhookUrl, 'URL')}>
                <Copy className="size-3.5" aria-hidden />
              </IconButton>
            </div>
          </div>
          <div className="min-w-0 rounded-control bg-inset px-3 py-2.5">
            <p className="text-meta text-fg-subtle">Segredo</p>
            <div className="mt-1 flex items-center justify-between gap-2">
              <span className="min-w-0 truncate text-meta text-fg-muted">
                {project.webhook_secret_configured ? 'Configurado (não exibível)' : 'Não configurado'}
              </span>
              <Button variant="secondary" size="sm" onClick={() => setConfirmRotate(true)}>
                <KeyRound className="size-3.5" aria-hidden />
                {project.webhook_secret_configured ? 'Gerar novo' : 'Gerar segredo'}
              </Button>
            </div>
          </div>
        </div>

        <div className="text-meta text-fg-muted">
          <p className="text-body font-semibold text-fg">Como configurar no GitHub</p>
          <ol className="mt-1.5 list-inside list-decimal space-y-0.5">
            <li>No repositório, abra Settings → Webhooks → Add webhook.</li>
            <li>Cole a Payload URL acima e escolha Content type: application/json.</li>
            <li>Cole o segredo gerado aqui no campo Secret e selecione "Just the push event".</li>
            <li>Adicione abaixo as branches monitoradas e o perfil que será executado.</li>
          </ol>
        </div>

        <div className="border-t border-line pt-4">
          <p className="text-body font-semibold text-fg">Branches monitoradas</p>

          {branchTriggers.length === 0 ? (
            <p className="mt-2 rounded-control bg-inset px-3 py-3 text-center text-meta text-fg-subtle">
              Nenhuma branch monitorada. Builds automáticas só acontecem após configurar um gatilho.
            </p>
          ) : (
            <div className="mt-2 -mx-4">
              <TableShell>
                <thead>
                  <tr>
                    <Th>Padrão de branch</Th>
                    <Th>Perfil</Th>
                    <Th>Estado</Th>
                    <Th className="w-14" />
                  </tr>
                </thead>
                <tbody>
                  {branchTriggers.map((trigger) => (
                    <tr key={trigger.id} className="transition-colors hover:bg-fill-hover/60">
                      <Td className="font-mono text-meta">{trigger.branch_pattern}</Td>
                      <Td className="text-body">
                        {trigger.profile?.name ?? '—'}{' '}
                        {trigger.profile && !trigger.profile.is_active ? (
                          <span className="text-meta text-danger">(inativo)</span>
                        ) : null}
                      </Td>
                      <Td className="text-meta text-fg-muted">{trigger.is_active ? 'Ativo' : 'Inativo'}</Td>
                      <Td className="text-right">
                        <IconButton
                          label="Remover gatilho"
                          tone="danger"
                          size="sm"
                          onClick={() => setTriggerToRemove(trigger)}
                        >
                          <Trash2 className="size-3.5" aria-hidden />
                        </IconButton>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </TableShell>
            </div>
          )}

          <div className="mt-3 flex flex-wrap items-end gap-2">
            <Field label="Padrão de branch" className="min-w-40 flex-1">
              <Input
                value={newPattern}
                onChange={(event) => setNewPattern(event.target.value)}
                placeholder="main ou release/*"
              />
            </Field>
            <Field label="Perfil" className="min-w-40 flex-1">
              <Select value={newProfileId} onChange={(event) => setNewProfileId(event.target.value)}>
                <option value="">Selecione…</option>
                {profiles
                  .filter((profile) => profile.is_active)
                  .map((profile) => (
                    <option key={profile.id} value={profile.id}>
                      {profile.name}
                    </option>
                  ))}
              </Select>
            </Field>
            <Button
              disabled={!newPattern.trim() || !newProfileId}
              loading={addTrigger.isPending}
              onClick={() => addTrigger.mutate()}
            >
              <Plus className="size-4" aria-hidden /> Adicionar
            </Button>
          </div>
        </div>

        <div className="border-t border-line pt-4">
          <div className="flex items-center justify-between gap-2">
            <p className="text-body font-semibold text-fg">Entregas recentes</p>
            <IconButton
              label="Atualizar entregas"
              size="sm"
              onClick={() => deliveries.refetch()}
              disabled={deliveries.isFetching}
            >
              <RefreshCw className="size-3.5" aria-hidden />
            </IconButton>
          </div>

          {deliveries.isLoading ? (
            <LoadingBlock label="Carregando entregas…" />
          ) : (deliveries.data?.data.length ?? 0) === 0 ? (
            <EmptyState title="Nenhum evento recebido" description="Os eventos do GitHub aparecem aqui." />
          ) : (
            <div className="mt-2 -mx-4">
              <TableShell>
                <thead>
                  <tr>
                    <Th>Resultado</Th>
                    <Th>Referência</Th>
                    <Th>Commit</Th>
                    <Th>Mensagem</Th>
                    <Th className="text-right">Quando</Th>
                  </tr>
                </thead>
                <tbody>
                  {(deliveries.data?.data ?? []).map((delivery) => (
                    <tr key={delivery.id} className="transition-colors hover:bg-fill-hover/60">
                      <Td>
                        <WebhookStatusBadge status={delivery.status} label={delivery.status_label} />
                      </Td>
                      <Td className="font-mono text-meta">
                        {delivery.ref?.replace('refs/heads/', '') ?? '—'}
                      </Td>
                      <Td className="font-mono text-meta">{shortSha(delivery.sha)}</Td>
                      <Td
                        className="max-w-64 truncate text-meta text-fg-muted"
                        title={delivery.message ?? ''}
                      >
                        {delivery.message ?? '—'}
                      </Td>
                      <Td className="text-right text-meta whitespace-nowrap text-fg-muted">
                        {formatRelative(delivery.created_at)}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </TableShell>
            </div>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={confirmRotate}
        title="Gerar novo segredo do webhook"
        message="O segredo atual deixará de funcionar imediatamente. Atualize-o no GitHub após gerar o novo."
        confirmLabel="Gerar segredo"
        loading={rotate.isPending}
        destructive
        onClose={() => setConfirmRotate(false)}
        onConfirm={() => rotate.mutate()}
      />

      <ConfirmDialog
        open={triggerToRemove !== null}
        title="Remover gatilho"
        message={`Remover o monitoramento da branch "${triggerToRemove?.branch_pattern}"?`}
        confirmLabel="Remover"
        destructive
        loading={removeTrigger.isPending}
        onClose={() => setTriggerToRemove(null)}
        onConfirm={() => triggerToRemove && removeTrigger.mutate(triggerToRemove.id)}
      />

      <Modal
        open={secretModal !== null}
        title="Segredo do webhook gerado"
        onClose={() => setSecretModal(null)}
        footer={
          <Button variant="secondary" onClick={() => setSecretModal(null)}>
            Fechar
          </Button>
        }
      >
        <p className="text-meta text-fg-muted">
          Copie agora — este segredo não será exibido novamente. Cole-o no GitHub em Settings → Webhooks,
          campo "Secret".
        </p>
        <div className="mt-3 flex items-center gap-2 rounded-control bg-inset p-2">
          <code className="min-w-0 flex-1 break-all text-meta">{secretModal}</code>
          <Button variant="secondary" size="sm" onClick={() => secretModal && copy(secretModal, 'Segredo')}>
            <Copy className="size-3.5" aria-hidden /> Copiar
          </Button>
        </div>
      </Modal>
    </Card>
  )
}
