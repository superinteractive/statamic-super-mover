<?php

namespace Codedge\MoveEntries\Actions;

use Illuminate\Support\Facades\File;
use Statamic\Actions\Action;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;

class MoveEntriesToCollection extends Action
{
    public static function title(): string
    {
        return __('Move');
    }

    public function run($items, $values): void
    {
        $collection = Collection::findByHandle($values['collection']);

        if (! $collection) {
            return;
        }

        $usesDatabase = (bool) data_get(config('statamic.eloquent-driver'), 'entries.driver');

        if ($usesDatabase) {
            $this->processDb($items, $collection);

            return;
        }

        $this->processFlat($items, $collection);
    }

    protected function processDb($items, $collection): void
    {
        $blueprintHandle = $collection->entryBlueprint()->handle();
        $isMultisite = config('statamic.system.multisite');

        /** @var \Illuminate\Support\Collection $items */
        foreach ($items as $item) {
            $id = $item->origin()?->id() ?? $item->id();

            if ($item->origin()) {
                $item->origin()->collection($collection)->blueprint($blueprintHandle)->save();
            }

            $item->collection($collection)->blueprint($blueprintHandle)->save();

            if ($isMultisite) {
                EntryFacade::query()
                    ->where('origin', $id)
                    ->get()
                    ->each(fn ($entry) => $entry->collection($collection)->blueprint($blueprintHandle)->save());
            }
        }
    }

    protected function processFlat($items, $collection): void
    {
        $targetDir = preg_replace('/\.' . preg_quote(pathinfo($collection->path(), PATHINFO_EXTENSION), '/') . '$/', '', $collection->path());

        /** @var \Illuminate\Support\Collection $items */
        $items->each(function (StatamicEntry $item) use ($targetDir) {
            if (File::isDirectory($targetDir) && File::isWritable($targetDir)) {
                File::move($item->path(), $targetDir . '/' . pathinfo($item->path(), PATHINFO_BASENAME));
            }
        });
    }

    protected function fieldItems(): array
    {
        $collectionFilter = config('statamic.move-entries.collections', '*');
        $allowedCollections = $collectionFilter === '*' ? '*' : (array) $collectionFilter;

        $collections = Collection::all()
            ->filter(fn ($collection) => $allowedCollections === '*' || in_array($collection->handle(), $allowedCollections, true))
            ->mapWithKeys(fn ($collection) => [$collection->handle() => $collection->title()])
            ->sort();

        return [
            'collection' => [
                'type' => 'select',
                'validate' => 'required',
                'options' => $collections,
            ],
        ];
    }

    public function confirmationText(): string
    {
        /** @translation */
        return 'Are you sure you want to want to move this entry?|Are you sure you want to move these :count entries?';
    }

    public function visibleTo($item): bool
    {
        return $item instanceof StatamicEntry;
    }
}
