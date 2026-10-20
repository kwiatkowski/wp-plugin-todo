<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use WP_Error;

/**
 * Copies a project right below it in the same area: its settings (for now the colour) carry over,
 * the name is the given one or "Strona (kopia)", and – if asked – its open tasks are copied too.
 */
final class ProjectCopier
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
    ) {
    }

    /** @return array<string, mixed>|WP_Error the new project */
    public function copy(int $id, bool $withTasks, ?string $name = null): array|WP_Error
    {
        $project = $this->projects->find($id);

        if (is_wp_error($project)) {
            return $project;
        }

        $copy = $this->projects->duplicate($project, $project['areaId'], $name ?: $this->projects->copyName($project['name']));

        if (is_wp_error($copy)) {
            return $copy;
        }

        // right below the original among its area's projects
        $siblings = array_values(array_diff(array_column(array_filter(
            $this->projects->all(),
            static fn (array $item): bool => $item['areaId'] === $project['areaId'],
        ), 'id'), [$copy['id']]));
        array_splice($siblings, (int) array_search($id, $siblings, true) + 1, 0, [$copy['id']]);
        $this->projects->reorder($siblings);

        if ($withTasks && is_wp_error($result = $this->tasks->copyOpen($id, $copy['id']))) {
            return $result;
        }

        return $this->projects->find($copy['id']);
    }
}
