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
          <Th className="w-16">Build</Th>
          {showProject ? <Th>Projeto</Th> : null}
          <Th>Perfil</Th>
          <Th>Referência</Th>
          <Th>Estado</Th>
          <Th>Origem</Th>
          <Th className="text-right">Duração</Th>
          <Th className="text-right">Criada</Th>
        </tr>
      </thead>
      <tbody>
        {builds.map((build) => (
          <tr key={build.id} className="transition-colors hover:bg-fill-hover/60">
            <Td>
              <Link
                to={`/builds/${build.id}`}
                className="focus-ring rounded-sm font-mono text-meta font-medium text-accent hover:underline"
              >
                #{build.id}
              </Link>
            </Td>
            {showProject ? (
              <Td>
                <Link
                  to={`/projects/${build.project?.id}`}
                  className="focus-ring rounded-sm text-body text-fg hover:underline"
                >
                  {build.project?.name ?? '—'}
                </Link>
              </Td>
            ) : null}
            <Td className="text-fg-muted">{build.profile?.name ?? '—'}</Td>
            <Td>
              <span
                className="font-mono text-meta text-fg-muted"
                title={build.commit_sha ? `${build.commit_sha}${build.branch ? ` (${build.branch})` : ''}` : undefined}
              >
                {build.commit_sha ? shortSha(build.commit_sha) : (build.branch ?? '—')}
              </span>
            </Td>
            <Td>
              <BuildStatusBadge status={build.status} label={build.status_label} />
            </Td>
            <Td className="text-meta text-fg-muted">{build.source_label}</Td>
            <Td className="text-right text-meta tabular-nums text-fg-muted">
              {formatDuration(build.duration_ms)}
            </Td>
            <Td className="text-right text-meta whitespace-nowrap text-fg-muted">
              {formatRelative(build.created_at)}
            </Td>
          </tr>
        ))}
      </tbody>
    </TableShell>
  )
}
