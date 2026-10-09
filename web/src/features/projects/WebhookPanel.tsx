import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, KeyRound, Plus, RefreshCw, Trash2 } from 'lucide-react'
import { Button, Card, ConfirmDialog, EmptyState, Field, Input, LoadingBlock, Modal, Select, TableShell, Td, Th } from '@/components/ui'
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
      title="Integração com GitHub"
      description="Executa builds automaticamente em push de branches monitoradas."
      actions={
        <label className="flex items-center gap-2 text-xs text-[var(--app-fg-muted)]">
          <input
            type="checkbox"
            className="size-4 accent-[var(--app-primary)]"
            checked={project.webhook_enabled}
            disabled={toggleAutomation.isPending}
            onChange={(event) => toggleAutomation.mutate(event.target.checked)}
          />
          Automação ativa
        </label>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="rounded-md border border-[var(--app-border)] bg-[var(--app-surface-2)] p-3">
            <p className="text-[11px] font-medium tracking-wide text-[var(--app-fg-subtle)] uppercase">Payload URL</p>
            <div className="mt-1 flex items-center gap-2">
              <code className="min-w-0 flex-1 truncate text-xs">{webhookUrl}</code>
              <Button variant="ghost" size="sm" onClick={() => copy(webhookUrl, 'URL')}>
                <Copy className="size-3.5" />
              </Button>
            </div>
          </div>
          <div className="rounded-md border border-[var(--app-border)] bg-[var(--app-surface-2)] p-3">
            <p className="text-[11px] font-medium tracking-wide text-[var(--app-fg-subtle)] uppercase">Segredo</p>
            <div className="mt-1 flex items-center justify-between gap-2">
              <span className="text-xs text-[var(--app-fg-muted)]">
                {project.webhook_secret_configured ? 'Configurado (não exibível)' : 'Não configurado'}
              </span>
              <Button variant="secondary" size="sm" onClick={() => setConfirmRotate(true)}>
                <KeyRound className="size-3.5" /> {project.webhook_secret_configured ? 'Gerar novo' : 'Gerar segredo'}
              </Button>
            </div>
          </div>
        </div>

        <div className="text-xs text-[var(--app-fg-muted)]">
          <p className="font-medium text-[var(--app-fg)]">Como configurar no GitHub</p>
          <ol className="mt-1 list-inside list-decimal space-y-0.5">
            <li>No repositório, abra Settings → Webhooks → Add webhook.</li>
            <li>Cole a Payload URL acima e escolha Content type: application/json.</li>
            <li>Cole o segredo gerado aqui no campo Secret e selecione o evento "Just the push event".</li>
            <li>Adicione abaixo as branches monitoradas e o perfil que será executado.</li>
          </ol>
        </div>

        <div>
          <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
            <p className="text-[13px] font-medium">Branches monitoradas</p>
          </div>

          {branchTriggers.length === 0 ? (
            <p className="rounded-md border border-dashed border-[var(--app-border)] px-3 py-4 text-center text-xs text-[var(--app-fg-subtle)]">
              Nenhuma branch monitorada. Builds automáticas só acontecem após configurar um gatilho.
            </p>
          ) : (
            <TableShell>
              <thead>
                <tr>
                  <Th>Padrão de branch</Th>
                  <Th>Perfil</Th>
                  <Th>Estado</Th>
                  <Th className="w-16" />
                </tr>
              </thead>
              <tbody>
                {branchTriggers.map((trigger) => (
                  <tr key={trigger.id}>
                    <Td className="font-mono text-xs">{trigger.branch_pattern}</Td>
                    <Td className="text-sm">
                      {trigger.profile?.name ?? '—'}{' '}
                      {trigger.profile && !trigger.profile.is_active ? (
                        <span className="text-[11px] text-[var(--app-danger)]">(inativo)</span>
                      ) : null}
                    </Td>
                    <Td className="text-xs">{trigger.is_active ? 'Ativo' : 'Inativo'}</Td>
                    <Td>
                      <Button variant="ghost" size="sm" onClick={() => setTriggerToRemove(trigger)} aria-label="Remover gatilho">
                        <Trash2 className="size-3.5" />
                      </Button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
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
              size="sm"
              disabled={!newPattern.trim() || !newProfileId}
              loading={addTrigger.isPending}
              onClick={() => addTrigger.mutate()}
            >
              <Plus className="size-3.5" /> Adicionar
            </Button>
          </div>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <p className="text-[13px] font-medium">Entregas recentes</p>
            <Button variant="ghost" size="sm" onClick={() => deliveries.refetch()} loading={deliveries.isFetching}>
              <RefreshCw className="size-3.5" /> Atualizar
            </Button>
          </div>

          {deliveries.isLoading ? (
            <LoadingBlock label="Carregando entregas…" />
          ) : (deliveries.data?.data.length ?? 0) === 0 ? (
            <EmptyState title="Nenhum evento recebido" description="Os eventos do GitHub aparecerão aqui." />
          ) : (
            <TableShell>
              <thead>
                <tr>
                  <Th>Resultado</Th>
                  <Th>Referência</Th>
                  <Th>Commit</Th>
                  <Th>Mensagem</Th>
                  <Th>Quando</Th>
                </tr>
              </thead>
              <tbody>
                {(deliveries.data?.data ?? []).map((delivery) => (
                  <tr key={delivery.id}>
                    <Td>
                      <WebhookStatusBadge status={delivery.status} label={delivery.status_label} />
                    </Td>
                    <Td className="font-mono text-xs">{delivery.ref?.replace('refs/heads/', '') ?? '—'}</Td>
                    <Td className="font-mono text-xs">{shortSha(delivery.sha)}</Td>
                    <Td className="max-w-64 truncate text-xs text-[var(--app-fg-muted)]" title={delivery.message ?? ''}>
                      {delivery.message ?? '—'}
                    </Td>
                    <Td className="text-xs text-[var(--app-fg-muted)]">{formatRelative(delivery.created_at)}</Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
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
        <p className="text-xs text-[var(--app-fg-muted)]">
          Copie agora — este segredo não será exibido novamente. Cole-o no GitHub em Settings → Webhooks, campo "Secret".
        </p>
        <div className="mt-3 flex items-center gap-2 rounded-md border border-[var(--app-border)] bg-[var(--app-surface-2)] p-2">
          <code className="min-w-0 flex-1 break-all text-xs">{secretModal}</code>
          <Button variant="secondary" size="sm" onClick={() => secretModal && copy(secretModal, 'Segredo')}>
            <Copy className="size-3.5" /> Copiar
          </Button>
        </div>
      </Modal>
    </Card>
  )
}
