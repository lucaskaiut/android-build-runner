import { Link } from 'react-router'
import { BuildStatusBadge } from '@/components/StatusBadge'
import { TableShell, Td, Th } from '@/components/ui'
import { formatDuration, formatRelative, shortSha } from '@/lib/format'
import type { Build } from '@/lib/types'

export function BuildsTable({ builds, showProject = true }: { builds: Build[]; showProject?: boolean }) {
  return (
    <TableShell>
      <thead>
        <tr>
          <Th className="w-20">Build</Th>
          {showProject ? <Th>Projeto</Th> : null}
          <Th>Perfil</Th>
          <Th>Referência</Th>
          <Th>Estado</Th>
          <Th>Origem</Th>
          <Th>Duração</Th>
          <Th>Criada</Th>
        </tr>
      </thead>
      <tbody>
        {builds.map((build) => (
          <tr key={build.id} className="hover:bg-[var(--app-surface-2)]">
            <Td>
              <Link to={`/builds/${build.id}`} className="font-mono text-xs font-medium text-[var(--app-accent)] hover:underline">
                #{build.id}
              </Link>
            </Td>
            {showProject ? (
              <Td>
                <Link to={`/projects/${build.project?.id}`} className="text-sm hover:underline">
                  {build.project?.name ?? '—'}
                </Link>
              </Td>
            ) : null}
            <Td className="text-sm">{build.profile?.name ?? '—'}</Td>
            <Td>
              <span className="font-mono text-xs">
                {build.commit_sha ? shortSha(build.commit_sha) : (build.branch ?? '—')}
              </span>
            </Td>
            <Td>
              <BuildStatusBadge status={build.status} label={build.status_label} />
            </Td>
            <Td className="text-xs text-[var(--app-fg-muted)]">{build.source_label}</Td>
            <Td className="text-xs text-[var(--app-fg-muted)]">{formatDuration(build.duration_ms)}</Td>
            <Td className="text-xs text-[var(--app-fg-muted)]">{formatRelative(build.created_at)}</Td>
          </tr>
        ))}
      </tbody>
    </TableShell>
  )
}
