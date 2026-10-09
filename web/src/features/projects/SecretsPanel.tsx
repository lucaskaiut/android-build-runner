import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Plus, Trash2, Upload } from 'lucide-react'
import {
  Button,
  Card,
  ConfirmDialog,
  Field,
  IconButton,
  Input,
  Modal,
  TableShell,
  Td,
  Th,
} from '@/components/ui'
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
      <div className="flex flex-col gap-6">
        <div>
          <p className="text-body font-semibold text-fg">Variáveis de ambiente</p>

          {secrets.length === 0 ? (
            <p className="mt-2 rounded-control bg-inset px-3 py-3 text-center text-meta text-fg-subtle">
              Nenhuma variável configurada. Elas são injetadas no ambiente do script e mascaradas nos logs.
            </p>
          ) : (
            <div className="mt-2 -mx-4">
              <TableShell>
                <thead>
                  <tr>
                    <Th>Chave</Th>
                    <Th>Atualizada</Th>
                    <Th className="w-24 text-right">Ações</Th>
                  </tr>
                </thead>
                <tbody>
                  {secrets.map((secret) => (
                    <tr key={secret.id} className="transition-colors hover:bg-fill-hover/60">
                      <Td className="font-mono text-meta">{secret.key}</Td>
                      <Td className="text-meta text-fg-muted">{formatRelative(secret.updated_at)}</Td>
                      <Td className="text-right">
                        <div className="flex justify-end gap-0.5">
                          <IconButton
                            label={`Substituir valor de ${secret.key}`}
                            size="sm"
                            onClick={() => setRotateTarget(secret)}
                          >
                            <KeyRound className="size-3.5" aria-hidden />
                          </IconButton>
                          <IconButton
                            label={`Remover ${secret.key}`}
                            tone="danger"
                            size="sm"
                            onClick={() => setDeleteTarget(secret)}
                          >
                            <Trash2 className="size-3.5" aria-hidden />
                          </IconButton>
                        </div>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </TableShell>
            </div>
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
              disabled={!newKey.trim() || !newValue}
              loading={addSecret.isPending}
              onClick={() => addSecret.mutate()}
            >
              <Plus className="size-4" aria-hidden /> Adicionar
            </Button>
          </div>
        </div>

        <div className="border-t border-line pt-4">
          <p className="text-body font-semibold text-fg">Arquivos secretos</p>
          <p className="mt-1 text-meta text-fg-subtle">
            Use caminhos como <code>.env</code>, <code>credentials.json</code> ou{' '}
            <code>credentials/android/keystore.jks</code>. O arquivo é gravado no workspace a cada build.
          </p>

          {secretFiles.length === 0 ? (
            <p className="mt-2 rounded-control bg-inset px-3 py-3 text-center text-meta text-fg-subtle">
              Nenhum arquivo secreto configurado.
            </p>
          ) : (
            <div className="mt-2 -mx-4">
              <TableShell>
                <thead>
                  <tr>
                    <Th>Caminho</Th>
                    <Th className="text-right">Tamanho</Th>
                    <Th>Atualizado</Th>
                    <Th className="w-14 text-right">Ações</Th>
                  </tr>
                </thead>
                <tbody>
                  {secretFiles.map((secretFile) => (
                    <tr key={secretFile.id} className="transition-colors hover:bg-fill-hover/60">
                      <Td className="font-mono text-meta break-all">{secretFile.path}</Td>
                      <Td className="text-right text-meta tabular-nums text-fg-muted">
                        {formatBytes(secretFile.size)}
                      </Td>
                      <Td className="text-meta text-fg-muted">{formatRelative(secretFile.updated_at)}</Td>
                      <Td className="text-right">
                        <IconButton
                          label={`Remover ${secretFile.path}`}
                          tone="danger"
                          size="sm"
                          onClick={() => setFileToRemove(secretFile)}
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
                className="h-8 w-full min-w-0 text-meta text-fg-muted file:mr-2 file:h-8 file:cursor-pointer file:rounded-control file:border file:border-line-strong file:bg-surface file:px-3 file:text-meta file:font-medium file:text-fg hover:file:bg-fill-hover"
              />
            </Field>
            <Button
              disabled={!filePath.trim() || !file}
              loading={uploadFile.isPending}
              onClick={() => uploadFile.mutate()}
            >
              <Upload className="size-4" aria-hidden /> Enviar
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
        <p className="text-meta text-fg-muted">
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
