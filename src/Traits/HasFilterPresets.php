<?php

namespace Guiu\FilamentFilterPresets\Traits;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Support\Enums\ActionSize;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Guiu\FilamentFilterPresets\Models\FilterPreset;
use Illuminate\Support\Facades\Auth;

trait HasFilterPresets
{
    public function getTableFiltersForm(): Form
    {
        return parent::getTableFiltersForm();
    }

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        $result = parent::getDefaultTableRecordsPerPageSelectOption();

        $this->loadDefaultFilterPreset();

        return $result;
    }

    protected function loadDefaultFilterPreset(): void
    {
        try {
            if ($filterSetId = session($this->getFilterSetKey())) {
                $savedFilterSet = FilterPreset::where('id', $filterSetId)
                    ->where('resource_class', get_class($this))
                    ->first();
                if ($savedFilterSet) {
                    $this->tableFilters = [...$this->tableFilters, ...$savedFilterSet->filters];
                    $this->filterSetName = $savedFilterSet->name;
                }
                return;
            }

            $defaultPreset = FilterPreset::where('user_id', Auth::id())
                ->where('resource_class', get_class($this))
                ->where('is_default', true)
                ->first();

            if ($defaultPreset) {
                $this->tableFilters = [...$this->tableFilters, ...$defaultPreset->filters];
                $this->filterSetName = $defaultPreset->name . ' (Default)';
            }
        } catch (\Exception $e) {
            //
        }
    }

    protected function getFilterSetKey(): string
    {
        return get_class($this) . '_filter_set_id';
    }

    public static function getFilterPresetHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('save_filters')
                    ->label(__('filament-filter-presets::labels.save_filters'))
                    ->icon('heroicon-m-bookmark')
                    ->form([
                        TextInput::make('name')->label(__('filament-filter-presets::labels.filter_name'))->required(),
                        Textarea::make('description')->label(__('filament-filter-presets::labels.description')),
                        Toggle::make('is_default')->label(__('filament-filter-presets::labels.set_as_default')),
                    ])
                    ->action(function (array $data, $livewire): void {
                        $filters = $livewire->getTableFiltersForm()->getState();
                        $livewire->saveFilterPreset($data);
                    }),

                Action::make('load_filters')
                    ->label(__('filament-filter-presets::labels.load_filters'))
                    ->icon('heroicon-m-funnel')
                    ->form([
                        Select::make('preset_id')
                            ->label(__('filament-filter-presets::labels.select_saved_filter'))
                            ->options(function ($livewire) {
                                return FilterPreset::where('user_id', Auth::id())
                                    ->where('resource_class', get_class($livewire))
                                    ->orderBy('is_default', 'desc')
                                    ->orderBy('name', 'asc')
                                    ->get()
                                    ->mapWithKeys(function ($preset) {
                                        return [
                                            $preset->id => $preset->is_default
                                                ? $preset->name
                                                . ' ('
                                                . __('filament-filter-presets::labels.default')
                                                . ')'
                                                : $preset->name,
                                        ];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->placeholder(__('filament-filter-presets::labels.select_filter_placeholder')),
                    ])
                    ->action(function (array $data, $livewire) {
                        $livewire->loadFilterPreset((int) $data['preset_id']);
                    }),

                Action::make('manage_filters')
                    ->label(__('filament-filter-presets::labels.manage_filters'))
                    ->icon('heroicon-m-cog-6-tooth')
                    ->modalHeading(__('filament-filter-presets::labels.manage_filters'))
                    ->modalContent(fn($livewire) => view('filament-filter-presets::components.manage-filters-modal', [
                        'presets' => FilterPreset::where('user_id', Auth::id())->where(
                            'resource_class',
                            get_class($livewire),
                        )->get(),
                        'resourceClass' => get_class($livewire),
                    ])),
            ])
                ->label(__('filament-filter-presets::labels.filter_presets'))
                ->icon('heroicon-m-funnel')
                ->size(ActionSize::Small)
                ->color('gray')
                ->button(),
        ];
    }

    protected function saveFilterPreset(array $data): void
    {
        $rawFilters = $this->getTableFiltersForm()->getState();

        $filters = collect($rawFilters)->filter(
            fn($filter) => !empty($filter['values']) || !empty($filter['value']),
        )->all();

        if (empty($filters)) {
            Notification::make()
                ->title(__('filament-filter-presets::messages.error'))
                ->body(__('filament-filter-presets::messages.no_filters_to_save'))
                ->danger()
                ->send();
            return;
        }

        try {
            $filterPreset = FilterPreset::create([
                'user_id' => Auth::id(),
                'name' => $data['name'],
                'resource_class' => get_class($this),
                'filters' => $filters,
                'description' => $data['description'] ?? null,
                'is_default' => $data['is_default'] ?? false,
            ]);

            if ($data['is_default'] ?? false) {
                FilterPreset::where('resource_class', get_class($this))
                    ->where('user_id', Auth::id())
                    ->where('id', '!=', $filterPreset->id)
                    ->update(['is_default' => false]);
            }

            $this->filterSetName = $filterPreset->name;

            session([$this->getFilterSetKey() => $filterPreset->id]);

            Notification::make()
                ->title(__('filament-filter-presets::messages.success'))
                ->body(__('filament-filter-presets::messages.filter_saved', ['name' => $data['name']]))
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('filament-filter-presets::messages.error'))
                ->body(__('filament-filter-presets::messages.save_error', ['error' => $e->getMessage()]))
                ->danger()
                ->send();
        }
    }

    protected function loadFilterPreset(int $presetId): void
    {
        try {
            $preset = FilterPreset::where('id', $presetId)
                ->where('user_id', Auth::id())
                ->where('resource_class', get_class($this))
                ->firstOrFail();

            session([$this->getFilterSetKey() => $presetId]);

            $this->applyFilterPreset($preset);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body(__('filament-filter-presets::messages.preset_not_found'))
                ->danger()
                ->send();
        }

        session([$this->getFilterSetKey() => $presetId]);
    }

    protected function applyFilterPreset(FilterPreset $preset, bool $showNotification = true): void
    {
        try {
            $this->resetTableFiltersForm();
            $this->tableFilters = $preset->filters;
            $this->getTableFiltersForm()->fill($preset->filters);
            $this->filterSetName = $preset->name;

            if ($showNotification) {
                Notification::make()
                    ->title(__('filament-filter-presets::messages.success'))
                    ->body(__('filament-filter-presets::messages.filter_loaded', ['name' => $preset->name]))
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            if ($showNotification) {
                Notification::make()
                    ->title(__('filament-filter-presets::messages.error'))
                    ->body(__('filament-filter-presets::messages.load_error', ['error' => $e->getMessage()]))
                    ->danger()
                    ->send();
            }
        }
    }

    public function toggleDefaultFilter(int $presetId): void
    {
        try {
            $preset = FilterPreset::where('id', $presetId)
                ->where('user_id', Auth::id())
                ->where('resource_class', get_class($this))
                ->firstOrFail();

            if (!$preset->is_default) {
                FilterPreset::where('resource_class', get_class($this))
                    ->where('user_id', Auth::id())
                    ->where('id', '!=', $preset->id)
                    ->update(['is_default' => false]);
            }

            $preset->update(['is_default' => !$preset->is_default]);

            Notification::make()->title(__('filament-filter-presets::messages.success'))->success()->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('filament-filter-presets::messages.error'))
                ->body(__('filament-filter-presets::messages.save_error', ['error' => $e->getMessage()]))
                ->danger()
                ->send();
        }
    }

    public function deletePreset(int $presetId): void
    {
        try {
            FilterPreset::where('id', $presetId)
                ->where('user_id', Auth::id())
                ->where('resource_class', get_class($this))
                ->delete();

            Notification::make()
                ->title(__('filament-filter-presets::messages.success'))
                ->body(__('filament-filter-presets::messages.delete_success'))
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('filament-filter-presets::messages.error'))
                ->body(__('filament-filter-presets::messages.delete_error', ['error' => $e->getMessage()]))
                ->danger()
                ->send();
        }
    }

    /**
     * Apply default filter preset if it exists
     */
    public static function applyDefaultFilterPreset($livewire): void
    {
        if (!Auth::check()) {
            return;
        }

        $resourceClass = $livewire->getResource();
        $defaultPreset = FilterPreset::getDefaultForUserAndResource(Auth::id(), $resourceClass);

        if ($defaultPreset) {
            // Get normalized filters and convert them to Filament structure
            $normalizedFilters = $defaultPreset->getNormalizedFilters();
            $filamentFilters = FilterPreset::convertToFilamentStructure($normalizedFilters);

            // Apply the filters
            $livewire->tableFilters = $filamentFilters;
        }
    }

    /**
     * Generate a preview of what filters the preset contains
     */
    protected static function generateFilterPreview(FilterPreset $preset): string
    {
        if (empty($preset->description)) {
            return 'No description available';
        }

        return $preset->description;
    }

    /**
     * Define filter preset configuration for this resource
     * Override this method in your resource to define available filters
     */
    public static function getFilterPresetConfiguration(): array
    {
        return [];
    }

    public function removeTableFilter(
        string $filterName,
        ?string $field = null,
        bool $isRemovingAllFilters = false,
    ): void {
        session()->forget($this->getFilterSetKey());
        $this->filterSetName = null;
        parent::removeTableFilter($filterName, $field, $isRemovingAllFilters);
    }

    public function resetTableFiltersForm(): void
    {
        session()->forget($this->getFilterSetKey());
        $this->filterSetName = null;
        parent::resetTableFiltersForm();
    }

    public function removeTableFilters(): void
    {
        session()->forget($this->getFilterSetKey());
        $this->filterSetName = null;
        parent::removeTableFilters();
    }

    public function updating($property, $value)
    {
        if ($property !== 'mountedTableActionsData.0.preset_id') {
            $this->filterSetName = null;
        }
    }
}
