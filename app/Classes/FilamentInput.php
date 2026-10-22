<?php

namespace App\Classes;

use Exception;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Color\Factory as ColorFactory;

class FilamentInput
{
    /**
     * What an uploaded setting file may be called, by what it is (its content's type): an image or a font. Anything
     * else is kept as `.bin`, served as a download: never `.php` or `.html` on the public disk.
     */
    private const FILE_EXTENSIONS = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/svg+xml' => ['svg'],
        'image/x-icon' => ['ico'],
        'image/vnd.microsoft.icon' => ['ico'],
        'font/woff' => ['woff'],
        'font/woff2' => ['woff2'],
    ];

    /**
     * Convert array or object to Filament input
     *
     * @param  array|object  $setting
     * @return mixed
     */
    public static function convert($setting)
    {
        $component = self::build($setting);
        // A setting shown only with another one's value (e.g. ALTCHA's, with that captcha chosen).
        $hidden = is_array($setting) ? ($setting['hidden'] ?? null) : (!method_exists($setting, 'getName') ? ($setting->hidden ?? null) : null);

        return $hidden !== null && is_object($component) && method_exists($component, 'hidden') ? $component->hidden($hidden) : $component;
    }

    private static function build($setting)
    {
        // If its already a filament component, return it
        if (is_object($setting) && method_exists($setting, 'getName')) {
            return $setting;
        }
        if (is_array($setting)) {
            $setting = (object) $setting;
        }

        switch ($setting->type) {
            case 'select':
                return Select::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->options(function () use ($setting) {
                        /* Possiblities:
                            1. ['value1', 'value2', 'value3']
                            2. ['value1' => 'label1', 'value2' => 'label2', 'value3' => 'label3']
                            3. [[
                                    'value' => 'value1',
                                    'label' => 'label1',
                                ], [
                                    'value' => 'value2',
                                    'label' => 'label2',
                                ]]
                        */
                        if (isset($setting->options)) {
                            if (is_array($setting->options)) {
                                $options = [];
                                // Check if the keys are explicitly set or sequential
                                $keys = array_keys($setting->options);
                                $isSequential = $keys === range(0, count($keys) - 1);

                                foreach ($setting->options as $key => $value) {
                                    // Explicitly set keys (e.g., ['key1' => 'value1', 'key2' => 'value2'])
                                    if (is_array($value)) {
                                        $options[$value['value']] = $value['label'];
                                    } else {
                                        if ($isSequential) {
                                            // Sequential keys (e.g., [0 => 'value1', 1 => 'value2'])
                                            $options[$value] = $value;
                                        } else {
                                            $options[$key] = $value;
                                        }
                                    }
                                }

                                return $options;
                            } else {
                                return (array) $setting->options;
                            }
                        }

                        return [];
                    })
                    ->preload()
                    ->multiple($setting->multiple ?? false)
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->hintActions(isset($setting->action) ? [($setting->action)::make()] : [])
                    ->rules($setting->validation ?? []);

            case 'tags':
                return TagsInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->placeholder($setting->placeholder ?? '')
                    ->required($setting->required ?? false)
                    ->disabled($setting->disabled ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->rules($setting->validation ?? [])
                    ->nestedRecursiveRules($setting->nested_validation ?? [])
                    ->helperText($setting->description ?? null);

            case 'text':
                return TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->hintActions(isset($setting->action) ? [($setting->action)::make()] : [])
                    ->rules($setting->validation ?? []);

            case 'time':
                return TimePicker::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? null)
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? [])
                    ->seconds($setting->seconds ?? false);

            case 'textarea':
                return Textarea::make($setting->name)
                    ->rows($setting->rows ?? null)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->rules($setting->validation ?? [])
                    ->disabled($setting->disabled ?? false);

            case 'markdown':
                return MarkdownEditor::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->disableAllToolbarButtons($setting->disable_toolbar ?? false)
                    ->rules($setting->validation ?? [])
                    ->disabled($setting->disabled ?? false);
            case 'password':
                return TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->password()
                    ->revealable()
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? []);
            case 'email':
                return TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->email()
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? []);
            case 'number':
                return TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->numeric()
                    ->minValue($setting->min_value ?? null)
                    ->maxValue($setting->max_value ?? null)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? []);
            case 'color':
                $mode = $setting->color_mode ?? 'hsl';
                $color = ColorPicker::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? true)
                    ->hintActions(isset($setting->action) ? [($setting->action)::make()] : [])
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? [])
                    ->rules(function () {
                        return function ($attribute, $value, $fail) {
                            try {
                                ColorFactory::fromString(trim($value));
                            } catch (Exception $e) {
                                $fail('The :attribute must be a valid color.');
                            }
                        };
                    })
                    ->afterStateUpdated(function ($state, callable $set) use ($setting, $mode) {
                        try {
                            $set($setting->name, preg_replace('/,\s*/', ', ', ColorFactory::fromString(trim($state))->{'to' . ucfirst($mode)}()->__toString()));
                        } catch (Exception $e) {
                        }
                    });
                $color->$mode();

                return $color;
            case 'file':
                $input = FileUpload::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->acceptedFileTypes($setting->accept ?? [])
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->disk($setting->disk ?? 'public')
                    ->directory($setting->directory ?? null)
                    ->multiple($setting->multiple ?? false)
                    ->disabled($setting->disabled ?? false)
                    ->visibility($setting->visibility ?? 'private')
                    ->downloadable()
                    ->rules($setting->validation ?? [])
                    // No record to compare with: a path is the setting's own (stored now), or one of ours in its folder.
                    ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => self::ownFilePath($setting, $file));

                if (isset($setting->file_name)) {
                    $input->getUploadedFileNameForStorageUsing(
                        fn (): string => (string) $setting->file_name,
                    );
                } elseif ($setting->preserve_filenames ?? true) {
                    // The name stays readable (custom CSS refers to it), never one the web server would run.
                    // A name already on the disk (an earlier file, or one of this same save) gets a number: never overwritten.
                    $input->getUploadedFileNameForStorageUsing(
                        fn (TemporaryUploadedFile $file, FileUpload $component): string => self::storedFileName(
                            $file->getClientOriginalName(),
                            $file->getMimeType(),
                            fn (string $name): bool => Storage::disk($component->getDiskName())
                                ->exists(ltrim(($component->getDirectory() ?? '') . '/' . $name, '/')),
                        ),
                    );
                }

                return $input;

            case 'checkbox':
                return Checkbox::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? []);

            case 'placeholder':
                return Placeholder::make($setting->name)
                    ->content($setting->label ?? null)
                    ->helperText($setting->description ?? null)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary');

            default:
                throw new Exception("Unknown input type: {$setting->type}");
        }
    }

    /**
     * A file's stored name: its own name as a slug, with the extension of what it is (FILE_EXTENSIONS); `-2`, `-3`, …
     * while `$taken` says the name is used.
     *
     * @param  (callable(string): bool)|null  $taken
     */
    public static function storedFileName(string $originalName, ?string $mimeType, ?callable $taken = null): string
    {
        $originalName = basename(str_replace('\\', '/', $originalName));
        $name = Str::limit(Str::slug(pathinfo($originalName, PATHINFO_FILENAME)), 100, '') ?: 'file';
        $allowed = self::FILE_EXTENSIONS[(string) $mimeType] ?? ['bin'];
        $own = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $extension = in_array($own, $allowed, true) ? $own : $allowed[0];
        $stored = $name . '.' . $extension;
        for ($n = 2; $taken !== null && $taken($stored); $n++) {
            $stored = $name . '-' . $n . '.' . $extension;
        }

        return $stored;
    }

    /**
     * A path a setting's field may hold without a new upload: what is stored for it now, or a plain name in its own
     * folder (no other folder, no `..`).
     */
    public static function ownFilePath(object $setting, string $file): bool
    {
        if (in_array($file, array_filter((array) config('settings.' . $setting->name), 'is_string'), true)) {
            return true;
        }
        $directory = isset($setting->directory) ? trim((string) $setting->directory, '/') . '/' : '';

        return (bool) preg_match('~\A' . preg_quote($directory, '~') . '[A-Za-z0-9][A-Za-z0-9._-]{0,200}\z~', $file)
            && !str_contains($file, '..');
    }
}
