<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex gap-2">
            <button type="button" wire:click="$set('activeTab', 'live')" style="padding:6px 12px; border-radius:6px; background-color: {{ $activeTab === 'live' ? '#059669' : '#e5e7eb' }}; color: {{ $activeTab === 'live' ? 'white' : 'black' }};">
                Sections &amp; Fields
            </button>
            <button type="button" wire:click="$set('activeTab', 'archived')" style="padding:6px 12px; border-radius:6px; background-color: {{ $activeTab === 'archived' ? '#059669' : '#e5e7eb' }}; color: {{ $activeTab === 'archived' ? 'white' : 'black' }};">
                Archived
            </button>
        </div>

        @if ($activeTab === 'live')
            <div class="grid grid-cols-12 gap-4">
                <aside class="col-span-3 border rounded p-3">
                    <h3 class="font-semibold mb-2">Sections</h3>
                    <ul class="space-y-1 mb-3">
                        @forelse ($this->sections() as $section)
                            <li>
                                <button type="button" wire:click="$set('selectedSectionId', {{ $section->id }})" class="w-full text-left px-2 py-1 rounded {{ $selectedSectionId === $section->id ? 'bg-emerald-100' : '' }}">
                                    {{ $section->name }}
                                </button>
                            </li>
                        @empty
                            <li class="text-xs text-gray-500">No sections yet. Add one below.</li>
                        @endforelse
                    </ul>

                    <div class="border-t pt-3 space-y-2">
                        <input type="text" wire:model="newSectionName" placeholder="New section name" class="w-full border rounded px-2 py-1 text-sm" />
                        @error('newSectionName') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                        <button type="button" wire:click="submitNewSection" style="padding:6px 10px; border-radius:6px; background-color:#059669; color:white; font-size:12px;">
                            + Add Section
                        </button>
                    </div>
                </aside>

                <main class="col-span-9 border rounded p-3">
                    <h3 class="font-semibold mb-2">Fields</h3>
                    <ul class="space-y-1 mb-4">
                        @forelse ($this->fieldsForSelectedSection() as $field)
                            @if (config('davyas.visual_v2'))
                                <li>
                                    <div class="davya-card-row @if(!$field->is_built_in) davya-card-row--custom @elseif($field->is_required) davya-card-row--required @endif">
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <div class="flex flex-col">
                                                <button type="button" wire:click="moveFieldUp({{ $field->id }})" title="Move up" style="font-size:10px; line-height:1; padding:1px 4px;">▲</button>
                                                <button type="button" wire:click="moveFieldDown({{ $field->id }})" title="Move down" style="font-size:10px; line-height:1; padding:1px 4px;">▼</button>
                                            </div>
                                            <span class="font-medium">{{ $field->label }}</span>
                                            <span class="text-xs text-gray-500">{{ $field->key }}</span>
                                            <span class="text-xs px-2 py-0.5 bg-gray-200 rounded">{{ $field->type }}</span>
                                            <label class="text-xs flex items-center gap-1 {{ $field->key === 'phone' ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' }}">
                                                <input
                                                    type="checkbox"
                                                    wire:click="toggleFieldRequired({{ $field->id }})"
                                                    @checked($field->is_required)
                                                    @disabled($field->key === 'phone')
                                                />
                                                <span>Required</span>
                                            </label>
                                            @if ($field->key === 'phone')
                                                <span class="text-xs text-gray-500 italic">(locked)</span>
                                            @endif
                                            @if ($field->is_built_in)
                                                <span class="text-xs px-2 py-0.5 bg-amber-100 rounded">🔒 built-in</span>
                                            @endif
                                            <button type="button" wire:click="startEdit({{ $field->id }})" style="padding:3px 8px; border-radius:4px; background-color:#2563eb; color:white; font-size:11px;">
                                                Edit
                                            </button>
                                            @error('required_'.$field->id) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>

                                        @if ($editingFieldId === $field->id)
                                            <div class="mt-2 p-3 bg-gray-50 border rounded space-y-2">
                                                <div>
                                                    <label class="text-xs text-gray-600">Label</label>
                                                    <input type="text" wire:model="editFieldLabel" class="w-full border rounded px-2 py-1 text-sm" />
                                                    @error('editFieldLabel') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                                </div>
                                                @if (in_array($field->type, ['dropdown','multiselect'], true))
                                                    <div>
                                                        <label class="text-xs text-gray-600">Options (one per line)</label>
                                                        <textarea wire:model="editFieldOptionsText" rows="5" class="w-full border rounded px-2 py-1 text-sm"></textarea>
                                                        @error('editFieldOptionsText') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                                        @if (in_array($field->key, ['owner_id','lead_source','stage'], true))
                                                            <div class="text-xs text-amber-700 mt-1">Note: options for this field are loaded dynamically from {{ $field->key === 'stage' ? 'pipeline config' : 'users' }}; edits here won't affect the student form.</div>
                                                        @endif
                                                    </div>
                                                @endif
                                                <div class="flex gap-2">
                                                    <button type="button" wire:click="saveEdit" style="padding:5px 12px; border-radius:4px; background-color:#059669; color:white; font-size:12px;">Save</button>
                                                    <button type="button" wire:click="cancelEdit" style="padding:5px 12px; border-radius:4px; background-color:#e5e7eb; color:black; font-size:12px;">Cancel</button>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @else
                                <li class="border-b py-2">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <div class="flex flex-col">
                                            <button type="button" wire:click="moveFieldUp({{ $field->id }})" title="Move up" style="font-size:10px; line-height:1; padding:1px 4px;">▲</button>
                                            <button type="button" wire:click="moveFieldDown({{ $field->id }})" title="Move down" style="font-size:10px; line-height:1; padding:1px 4px;">▼</button>
                                        </div>
                                        <span class="font-medium">{{ $field->label }}</span>
                                        <span class="text-xs text-gray-500">{{ $field->key }}</span>
                                        <span class="text-xs px-2 py-0.5 bg-gray-200 rounded">{{ $field->type }}</span>
                                        <label class="text-xs flex items-center gap-1 {{ $field->key === 'phone' ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' }}">
                                            <input
                                                type="checkbox"
                                                wire:click="toggleFieldRequired({{ $field->id }})"
                                                @checked($field->is_required)
                                                @disabled($field->key === 'phone')
                                            />
                                            <span>Required</span>
                                        </label>
                                        @if ($field->key === 'phone')
                                            <span class="text-xs text-gray-500 italic">(locked)</span>
                                        @endif
                                        @if ($field->is_built_in)
                                            <span class="text-xs px-2 py-0.5 bg-amber-100 rounded">🔒 built-in</span>
                                        @endif
                                        <button type="button" wire:click="startEdit({{ $field->id }})" style="padding:3px 8px; border-radius:4px; background-color:#2563eb; color:white; font-size:11px;">
                                            Edit
                                        </button>
                                        @error('required_'.$field->id) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    @if ($editingFieldId === $field->id)
                                        <div class="mt-2 p-3 bg-gray-50 border rounded space-y-2">
                                            <div>
                                                <label class="text-xs text-gray-600">Label</label>
                                                <input type="text" wire:model="editFieldLabel" class="w-full border rounded px-2 py-1 text-sm" />
                                                @error('editFieldLabel') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                            </div>
                                            @if (in_array($field->type, ['dropdown','multiselect'], true))
                                                <div>
                                                    <label class="text-xs text-gray-600">Options (one per line)</label>
                                                    <textarea wire:model="editFieldOptionsText" rows="5" class="w-full border rounded px-2 py-1 text-sm"></textarea>
                                                    @error('editFieldOptionsText') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                                    @if (in_array($field->key, ['owner_id','lead_source','stage'], true))
                                                        <div class="text-xs text-amber-700 mt-1">Note: options for this field are loaded dynamically from {{ $field->key === 'stage' ? 'pipeline config' : 'users' }}; edits here won't affect the student form.</div>
                                                    @endif
                                                </div>
                                            @endif
                                            <div class="flex gap-2">
                                                <button type="button" wire:click="saveEdit" style="padding:5px 12px; border-radius:4px; background-color:#059669; color:white; font-size:12px;">Save</button>
                                                <button type="button" wire:click="cancelEdit" style="padding:5px 12px; border-radius:4px; background-color:#e5e7eb; color:black; font-size:12px;">Cancel</button>
                                            </div>
                                        </div>
                                    @endif
                                </li>
                            @endif
                        @empty
                            <li class="text-xs text-gray-500">No fields in this section yet.</li>
                        @endforelse
                    </ul>

                    <div class="border-t pt-3">
                        <h4 class="font-semibold text-sm mb-2">Add a new field</h4>
                        @if (!$selectedSectionId)
                            <p class="text-xs text-gray-500">Create a section first, then you can add fields to it.</p>
                        @else
                            <div class="grid grid-cols-2 gap-3">
                                <div class="col-span-2">
                                    <label class="text-xs text-gray-600">Section (where this field goes)</label>
                                    <select wire:model="newFieldSectionId" class="w-full border rounded px-2 py-1 text-sm">
                                        <option value="">— pick a section —</option>
                                        @foreach ($this->sections() as $section)
                                            <option value="{{ $section->id }}" {{ ($newFieldSectionId ?? $selectedSectionId) == $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('newFieldSectionId') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                                <div>
                                    <label class="text-xs text-gray-600">Label (your name for the field)</label>
                                    <input type="text" wire:model="newFieldLabel" placeholder="e.g. Father's Phone" class="w-full border rounded px-2 py-1 text-sm" />
                                    @error('label') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                    @error('newFieldLabel') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                                <div>
                                    <label class="text-xs text-gray-600">Type</label>
                                    <select wire:model.live="newFieldType" class="w-full border rounded px-2 py-1 text-sm">
                                        <option value="text">Text (single line)</option>
                                        <option value="textarea">Text (multi-line)</option>
                                        <option value="number">Number</option>
                                        <option value="date">Date</option>
                                        <option value="email">Email</option>
                                        <option value="dropdown">Dropdown (pick one)</option>
                                        <option value="multiselect">Multi-select (pick many)</option>
                                        <option value="checkbox">Checkbox (yes/no)</option>
                                    </select>
                                </div>
                                @if (in_array($newFieldType, ['dropdown','multiselect'], true))
                                    <div class="col-span-2">
                                        <label class="text-xs text-gray-600">Options (one per line)</label>
                                        <textarea wire:model="newFieldOptionsText" rows="4" placeholder="Option A&#10;Option B&#10;Option C" class="w-full border rounded px-2 py-1 text-sm"></textarea>
                                        @error('newFieldOptionsText') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                                    </div>
                                @endif
                                <label class="text-xs flex items-center gap-2">
                                    <input type="checkbox" wire:model="newFieldRequired" /> Required on student form
                                </label>
                                <label class="text-xs flex items-center gap-2">
                                    <input type="checkbox" wire:model="newFieldShowInTable" /> Show in student list (table)
                                </label>
                                <label class="text-xs flex items-center gap-2">
                                    <input type="checkbox" wire:model="newFieldShowInKanban" /> Show on Kanban tile
                                </label>
                                <label class="text-xs flex items-center gap-2">
                                    <input type="checkbox" wire:model="newFieldShowInImport" /> Accept in CSV import
                                </label>
                            </div>
                            <div class="mt-3">
                                <button type="button" wire:click="submitNewField" style="padding:6px 12px; border-radius:6px; background-color:#059669; color:white; font-size:13px;">
                                    + Add Field
                                </button>
                                @error('type') <span class="text-xs text-red-600 ml-2">{{ $message }}</span> @enderror
                                @error('section_id') <span class="text-xs text-red-600 ml-2">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>
                </main>
            </div>
        @else
            <div class="border rounded p-3">
                <h3 class="font-semibold mb-2">Archived fields</h3>
                <ul class="space-y-1">
                    @forelse ($this->archivedFields() as $field)
                        <li>{{ $field->label }} — archived {{ $field->archived_at->diffForHumans() }}</li>
                    @empty
                        <li class="text-xs text-gray-500">No archived fields.</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
