<?php

namespace App\Services\Labs;

use App\Models\Lab;

/**
 * Which labs to talk to, from medvault_active_labs() and friends.
 *
 * Memoised per request like the plugin's static caches; call flush() after
 * changing lab settings within the same process.
 */
final class LabRegistry
{
    /** @var list<LabConfig>|null */
    private ?array $active = null;

    /** @var array<int, ?LabConfig> */
    private array $byId = [];

    /**
     * Every lab with an enabled results API, lowest priority first.
     *
     * Ties keep the order WordPress's get_posts() returned them in, newest
     * first, because the first lab supplies the flat lab_name/lab_token that
     * pre-multi-lab app builds read. With nothing configured, the legacy
     * Verdun account, rather than no results at all.
     *
     * @return list<LabConfig>
     */
    public function active(): array
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $labs = Lab::published()
            ->where('api_enabled', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Lab $lab) => $this->remember($lab))
            ->filter(fn (?LabConfig $cfg) => $cfg !== null && $cfg->enabled)
            ->values()
            ->all();

        // usort() is stable on PHP 8, so equal priorities keep the order above.
        usort($labs, static fn (LabConfig $a, LabConfig $b) => $a->priority <=> $b->priority);

        return $this->active = $labs === [] ? [LabConfig::legacy()] : $labs;
    }

    /** One lab by id, any status; the legacy account for id 0. */
    public function find(int $labId): ?LabConfig
    {
        if ($labId === 0) {
            return LabConfig::legacy();
        }
        if (! array_key_exists($labId, $this->byId)) {
            $lab = Lab::find($labId);
            $this->byId[$labId] = $lab === null ? null : LabConfig::fromLab($lab);
        }

        return $this->byId[$labId];
    }

    /**
     * medvault_find_lab_id_by_name(): exact title first, then a loose match.
     * Only used to name the legacy account's lab after it logs in.
     */
    public function findIdByName(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $exact = Lab::where('title', $name)->orderBy('id')->value('id');
        if ($exact !== null) {
            return (int) $exact;
        }

        $loose = Lab::published()
            ->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($name).'%'])
            ->orderBy('id')
            ->value('id');

        return $loose === null ? null : (int) $loose;
    }

    public function flush(): void
    {
        $this->active = null;
        $this->byId = [];
    }

    private function remember(Lab $lab): ?LabConfig
    {
        return $this->byId[(int) $lab->id] = LabConfig::fromLab($lab);
    }
}
