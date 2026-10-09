import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Plus, Trash2, Upload } from 'lucide-react'
import { Button, Card, ConfirmDialog, Field, Input, Modal, TableShell, Td, Th } from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage } from '@/lib/api'
import { formatBytes, formatRelative } from '@/lib/format'
import type { ProjectSecret, ProjectSecretFile } from '@/lib/types'

export function SecretsPanel({ projectId }: { projectId: number }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const [newKey, setNewKey] = useState('')
  const [newValue, setNewValue] = useState('')
  const [rotateTarget, setRotateTarget] = useState<ProjectSecret | null>(null)
  const [rotateValue, setRotateValue] = useState('')
  const [deleteTarget, setDeleteTarget] = useState<ProjectSecret | null>(null)
  const [filePath, setFilePath] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [fileToRemove, setFileToRemove] = useState<ProjectSecretFile | null>(null)

  const secretsQuery = useQuery({
    queryKey: ['project-secrets', projectId],
    queryFn: () => api.secrets(projectId),
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['project-secrets', projectId] })
  }

  const addSecret = useMutation({
    mutationFn: () => api.createSecret(projectId, { key: newKey.trim(), value: newValue }),
    onSuccess: () => {
      invalidate()
      setNewKey('')
      setNewValue('')
      toast.success('Segredo salvo')
    },
    onError: (error) => toast.error('Falha ao salvar o segredo', apiErrorMessage(error)),
  })

  const rotateSecret = useMutation({
    mutationFn: ({ id, value }: { id: number; value: string }) => api.updateSecret(id, value),
    onSuccess: () => {
      invalidate()
      setRotateTarget(null)
      setRotateValue('')
      toast.success('Valor substituído')
    },
    onError: (error) => toast.error('Falha ao substituir o valor', apiErrorMessage(error)),
  })

  const removeSecret = useMutation({
    mutationFn: (id: number) => api.deleteSecret(id),
    onSuccess: () => {
      invalidate()
      setDeleteTarget(null)
      toast.success('Segredo removido')
    },
    onError: (error) => toast.error('Falha ao remover', apiErrorMessage(error)),
  })

  const uploadFile = useMutation({
    mutationFn: () => api.uploadSecretFile(projectId, filePath.trim(), file as File),
    onSuccess: () => {
      invalidate()
      setFilePath('')
      setFile(null)
      if (fileInputRef.current) fileInputRef.current.value = ''
      toast.success('Arquivo secreto enviado')
    },
    onError: (error) => toast.error('Falha ao enviar o arquivo', apiErrorMessage(error)),
  })

  const removeFile = useMutation({
    mutationFn: (id: number) => api.deleteSecretFile(id),
    onSuccess: () => {
      invalidate()
      setFileToRemove(null)
      toast.success('Arquivo removido')
    },
    onError: (error) => toast.error('Falha ao remover', apiErrorMessage(error)),
  })

  const secrets = secretsQuery.data?.secrets ?? []
  const secretFiles = secretsQuery.data?.secret_files ?? []

  return (
    <Card
      title="Segredos do projeto"
      description="Variáveis e arquivos aplicados no workspace antes do script. Os valores são write-only: não podem ser exibidos novamente."
    >
      <div className="flex flex-col gap-5">
        <div>
          <p className="mb-2 text-[13px] font-medium">Variáveis de ambiente</p>

          {secrets.length === 0 ? (
            <p className="rounded-md border border-dashed border-[var(--app-border)] px-3 py-4 text-center text-xs text-[var(--app-fg-subtle)]">
              Nenhuma variável configurada. Elas são injetadas no ambiente do script e mascaradas nos logs.
            </p>
          ) : (
            <TableShell>
              <thead>
                <tr>
                  <Th>Chave</Th>
                  <Th>Atualizada</Th>
                  <Th className="w-32 text-right">Ações</Th>
                </tr>
              </thead>
              <tbody>
                {secrets.map((secret) => (
                  <tr key={secret.id}>
                    <Td className="font-mono text-xs">{secret.key}</Td>
                    <Td className="text-xs text-[var(--app-fg-muted)]">{formatRelative(secret.updated_at)}</Td>
                    <Td className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setRotateTarget(secret)}
                          aria-label={`Substituir valor de ${secret.key}`}
                        >
                          <KeyRound className="size-3.5" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setDeleteTarget(secret)}
                          aria-label={`Remover ${secret.key}`}
                        >
                          <Trash2 className="size-3.5" />
                        </Button>
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
          )}

          <div className="mt-3 flex flex-wrap items-end gap-2">
            <Field label="Chave" className="min-w-40 flex-1">
              <Input
                value={newKey}
                onChange={(event) => setNewKey(event.target.value)}
                placeholder="TENANT_BRANDS_TOKEN"
                autoComplete="off"
              />
            </Field>
            <Field label="Valor" className="min-w-40 flex-1">
              <Input
                type="password"
                value={newValue}
                onChange={(event) => setNewValue(event.target.value)}
                placeholder="valor secreto"
                autoComplete="new-password"
              />
            </Field>
            <Button
              size="sm"
              disabled={!newKey.trim() || !newValue}
              loading={addSecret.isPending}
              onClick={() => addSecret.mutate()}
            >
              <Plus className="size-3.5" /> Adicionar
            </Button>
          </div>
        </div>

        <div>
          <p className="mb-2 text-[13px] font-medium">Arquivos secretos</p>
          <p className="mb-2 text-xs text-[var(--app-fg-subtle)]">
            Use caminhos como <code className="font-mono">.env</code>, <code className="font-mono">credentials.json</code> ou{' '}
            <code className="font-mono">credentials/android/keystore.jks</code>. O arquivo é gravado no workspace a cada build.
          </p>

          {secretFiles.length === 0 ? (
            <p className="rounded-md border border-dashed border-[var(--app-border)] px-3 py-4 text-center text-xs text-[var(--app-fg-subtle)]">
              Nenhum arquivo secreto configurado.
            </p>
          ) : (
            <TableShell>
              <thead>
                <tr>
                  <Th>Caminho</Th>
                  <Th>Tamanho</Th>
                  <Th>Atualizado</Th>
                  <Th className="w-16 text-right">Ações</Th>
                </tr>
              </thead>
              <tbody>
                {secretFiles.map((secretFile) => (
                  <tr key={secretFile.id}>
                    <Td className="font-mono text-xs break-all">{secretFile.path}</Td>
                    <Td className="text-xs">{formatBytes(secretFile.size)}</Td>
                    <Td className="text-xs text-[var(--app-fg-muted)]">{formatRelative(secretFile.updated_at)}</Td>
                    <Td className="text-right">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setFileToRemove(secretFile)}
                        aria-label={`Remover ${secretFile.path}`}
                      >
                        <Trash2 className="size-3.5" />
                      </Button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
          )}

          <div className="mt-3 flex flex-wrap items-end gap-2">
            <Field label="Caminho no workspace" className="min-w-40 flex-1">
              <Input
                value={filePath}
                onChange={(event) => setFilePath(event.target.value)}
                placeholder="credentials/android/keystore.jks"
                autoComplete="off"
              />
            </Field>
            <Field label="Arquivo" className="min-w-40 flex-1">
              <input
                ref={fileInputRef}
                type="file"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                className="w-full text-xs text-[var(--app-fg-muted)] file:mr-2 file:rounded-md file:border file:border-[var(--app-border-strong)] file:bg-[var(--app-surface-2)] file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-[var(--app-fg)]"
              />
            </Field>
            <Button
              size="sm"
              disabled={!filePath.trim() || !file}
              loading={uploadFile.isPending}
              onClick={() => uploadFile.mutate()}
            >
              <Upload className="size-3.5" /> Enviar
            </Button>
          </div>
        </div>
      </div>

      <Modal
        open={rotateTarget !== null}
        title={`Substituir valor de ${rotateTarget?.key ?? ''}`}
        onClose={() => {
          setRotateTarget(null)
          setRotateValue('')
        }}
        footer={
          <>
            <Button
              variant="secondary"
              onClick={() => {
                setRotateTarget(null)
                setRotateValue('')
              }}
            >
              Cancelar
            </Button>
            <Button
              disabled={!rotateValue}
              loading={rotateSecret.isPending}
              onClick={() => rotateTarget && rotateSecret.mutate({ id: rotateTarget.id, value: rotateValue })}
            >
              Substituir
            </Button>
          </>
        }
      >
        <p className="text-xs text-[var(--app-fg-muted)]">
          O valor atual não pode ser exibido. Informe o novo valor — ele será usado na próxima build.
        </p>
        <div className="mt-3">
          <Field label="Novo valor">
            <Input
              type="password"
              value={rotateValue}
              onChange={(event) => setRotateValue(event.target.value)}
              autoComplete="new-password"
            />
          </Field>
        </div>
      </Modal>

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Remover segredo"
        message={`Remover a variável "${deleteTarget?.key ?? ''}"? As próximas builds deixarão de recebê-la.`}
        confirmLabel="Remover"
        destructive
        loading={removeSecret.isPending}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && removeSecret.mutate(deleteTarget.id)}
      />

      <ConfirmDialog
        open={fileToRemove !== null}
        title="Remover arquivo secreto"
        message={`Remover o arquivo "${fileToRemove?.path ?? ''}"? As próximas builds deixarão de recebê-lo.`}
        confirmLabel="Remover"
        destructive
        loading={removeFile.isPending}
        onClose={() => setFileToRemove(null)}
        onConfirm={() => fileToRemove && removeFile.mutate(fileToRemove.id)}
      />
    </Card>
  )
}
