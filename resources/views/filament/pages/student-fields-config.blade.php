<x-filament-panels::page>
    @if (config('davyas.visual_v2'))
        <div class="davya-fields-shell" style="display:flex; flex-direction:column; gap: var(--s-4);">
            <div style="display:flex; gap:4px; background: var(--border-muted); padding:3px; border-radius:var(--r-md); width:fit-content;">
                <button type="button" wire:click="$set('activeTab', 'live')"
                        style="padding:6px 14px; border-radius:var(--r-sm); font-size:var(--fs-12); font-weight:600; border:0; cursor:pointer;
                               {{ $activeTab === 'live' ? 'background: var(--surface); color: var(--text); box-shadow: var(--elev-1);' : 'background: transparent; color: var(--text-sub);' }}">
                    Sections &amp; Fields
                </button>
                <button type="button" wire:click="$set('activeTab', 'archived')"
                        style="padding:6px 14px; border-radius:var(--r-sm); font-size:var(--fs-12); font-weight:600; border:0; cursor:pointer;
                               {{ $activeTab === 'archived' ? 'background: var(--surface); color: var(--text); box-shadow: var(--elev-1);' : 'background: transparent; color: var(--text-sub);' }}">
                    Archived
                </button>
            </div>

            @if ($activeTab === 'live')
                <div style="display:grid; grid-template-columns: 260px 1fr; gap: var(--s-4);">
                    {{-- LEFT: sections rail --}}
                    <aside class="davya-section-card" style="padding: var(--s-3); margin:0;">
                        <div class="davya-section-card-title" style="margin-bottom: var(--s-2);">Sections</div>
                        <div style="display:flex; flex-direction:column; gap:2px; margin-bottom: var(--s-3);">
                            @forelse ($this->sections() as $section)
                                @php $isActive = $selectedSectionId === $section->id; @endphp
                                <button type="button" wire:click="$set('selectedSectionId', {{ $section->id }})"
                                        style="text-align:left; padding:7px 10px; border-radius:var(--r-md); border:0; cursor:pointer; font-size:var(--fs-12); font-weight: {{ $isActive ? 600 : 500 }};
                                               {{ $isActive ? 'background: var(--brand-50); color: var(--brand-700);' : 'background: transparent; color: var(--text);' }}">
                                    {{ $section->name }}
                                </button>
                            @empty
                                <div style="font-size: var(--fs-11); color: var(--text-sub); padding: var(--s-2);">No sections yet. Add one below.</div>
                            @endforelse
                        </div>

                        <div style="border-top:1px solid var(--border); padding-top: var(--s-3); display:flex; flex-direction:column; gap:6px;">
                            <input type="text" wire:model="newSectionName" placeholder="New section name"
                                   style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12);" />
                            @error('newSectionName') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                            <button type="button" wire:click="submitNewSection"
                                    style="padding:6px 10px; border-radius:var(--r-md); background: var(--brand-600); color:white; font-size:var(--fs-11); font-weight:600; border:0; cursor:pointer;">
                                + Add Section
                            </button>
                        </div>
                    </aside>

                    {{-- RIGHT: fields pane --}}
                    <main class="davya-section-card" style="padding: var(--s-4); margin:0;">
                        <div class="davya-section-card-title" style="margin-bottom: var(--s-3);">Fields</div>

                        <div style="display:flex; flex-direction:column; gap:6px; margin-bottom: var(--s-4);">
                            @forelse ($this->fieldsForSelectedSection() as $field)
                                <div class="davya-field-row @if(!$field->is_built_in) davya-field-row--custom @elseif($field->is_required) davya-field-row--required @endif"
                                     style="background: var(--surface); border:1px solid var(--border); border-radius:var(--r-md); padding:10px 12px; display:flex; flex-direction:column; gap:6px; position:relative;">

                                    {{-- accent bar --}}
                                    @if (!$field->is_built_in)
                                        <span style="position:absolute; left:0; top:10px; bottom:10px; width:3px; background: var(--brand-500); border-radius:0 3px 3px 0;"></span>
                                    @elseif ($field->is_required)
                                        <span style="position:absolute; left:0; top:10px; bottom:10px; width:3px; background: var(--danger); border-radius:0 3px 3px 0;"></span>
                                    @endif

                                    <div style="display:flex; align-items:center; gap: var(--s-3); padding-left: 6px;">
                                        {{-- drag handle --}}
                                        <div style="display:flex; flex-direction:column; gap:2px; color: var(--text-muted); flex-shrink:0;">
                                            <button type="button" wire:click="moveFieldUp({{ $field->id }})" title="Move up"
                                                    style="border:0; background:transparent; color:inherit; font-size:10px; line-height:1; padding:0; cursor:pointer;">▲</button>
                                            <button type="button" wire:click="moveFieldDown({{ $field->id }})" title="Move down"
                                                    style="border:0; background:transparent; color:inherit; font-size:10px; line-height:1; padding:0; cursor:pointer;">▼</button>
                                        </div>

                                        {{-- label + key --}}
                                        <div style="flex:1; min-width:0;">
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <span style="font-weight:600; color: var(--text); font-size: var(--fs-13);">{{ $field->label }}</span>
                                                @if (!$field->is_built_in)
                                                    <span title="Custom field" style="width:6px; height:6px; border-radius:50%; background: var(--brand-500); display:inline-block;"></span>
                                                @endif
                                                @if ($field->is_required)
                                                    <span title="Required" style="width:6px; height:6px; border-radius:50%; background: var(--danger); display:inline-block;"></span>
                                                @endif
                                            </div>
                                            <div style="font-size: var(--fs-10); color: var(--text-sub); font-family: ui-monospace, monospace;">{{ $field->key }}</div>
                                        </div>

                                        {{-- type badge --}}
                                        <span style="font-size: var(--fs-10); color: var(--text-sub); text-transform:capitalize; background: var(--border-muted); padding:2px 8px; border-radius: var(--r-pill);">{{ $field->type }}</span>

                                        {{-- built-in lock --}}
                                        @if ($field->is_built_in)
                                            <span title="Built-in" style="font-size: var(--fs-10); color: var(--text-sub);">🔒</span>
                                        @endif

                                        {{-- required toggle --}}
                                        <label style="display:flex; align-items:center; gap:4px; font-size: var(--fs-10); color: var(--text-sub); {{ $field->key === 'phone' ? 'opacity:0.5; cursor:not-allowed;' : 'cursor:pointer;' }}">
                                            <input type="checkbox"
                                                   wire:click="toggleFieldRequired({{ $field->id }})"
                                                   @checked($field->is_required)
                                                   @disabled($field->key === 'phone')
                                                   style="accent-color: var(--brand-600);" />
                                            <span>Required</span>
                                        </label>

                                        {{-- edit --}}
                                        <button type="button" wire:click="startEdit({{ $field->id }})"
                                                style="padding:4px 10px; border-radius: var(--r-md); background: transparent; color: var(--brand-600); border:1px solid var(--border); font-size: var(--fs-10); font-weight:600; cursor:pointer;"
                                                onmouseover="this.style.background='var(--brand-50)'"
                                                onmouseout="this.style.background='transparent'">
                                            Edit
                                        </button>
                                    </div>

                                    @error('required_'.$field->id) <div style="font-size:var(--fs-10); color: var(--danger); padding-left:6px;">{{ $message }}</div> @enderror

                                    @if ($editingFieldId === $field->id)
                                        <div style="margin-top:6px; padding:12px; background: var(--border-muted); border-radius: var(--r-md); display:flex; flex-direction:column; gap: var(--s-2);">
                                            <div>
                                                <label style="font-size:var(--fs-11); color: var(--text-sub); font-weight:500;">Label</label>
                                                <input type="text" wire:model="editFieldLabel"
                                                       style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12); background: var(--surface);" />
                                                @error('editFieldLabel') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                                            </div>
                                            @if (in_array($field->type, ['dropdown','multiselect'], true))
                                                <div>
                                                    <label style="font-size:var(--fs-11); color: var(--text-sub); font-weight:500;">Options (one per line)</label>
                                                    <textarea wire:model="editFieldOptionsText" rows="5"
                                                              style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12); background: var(--surface); font-family:inherit;"></textarea>
                                                    @error('editFieldOptionsText') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                                                    @if (in_array($field->key, ['owner_id','lead_source','stage'], true))
                                                        <div style="font-size: var(--fs-10); color: var(--warning); margin-top:4px;">Note: options for this field are loaded dynamically from {{ $field->key === 'stage' ? 'pipeline config' : 'users' }}; edits here won't affect the student form.</div>
                                                    @endif
                                                </div>
                                            @endif
                                            <div style="display:flex; gap:6px;">
                                                <button type="button" wire:click="saveEdit"
                                                        style="padding:5px 14px; border-radius: var(--r-md); background: var(--brand-600); color:white; font-size: var(--fs-11); font-weight:600; border:0; cursor:pointer;">Save</button>
                                                <button type="button" wire:click="cancelEdit"
                                                        style="padding:5px 14px; border-radius: var(--r-md); background: var(--surface); color: var(--text); font-size: var(--fs-11); font-weight:600; border:1px solid var(--border); cursor:pointer;">Cancel</button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div style="font-size: var(--fs-11); color: var(--text-sub); padding: var(--s-3);">No fields in this section yet.</div>
                            @endforelse
                        </div>

                        {{-- ADD-FIELD FORM --}}
                        <div style="border-top:1px solid var(--border); padding-top: var(--s-4);">
                            <div class="davya-section-card-title" style="margin-bottom: var(--s-3);">Add a new field</div>
                            @if (!$selectedSectionId)
                                <p style="font-size: var(--fs-11); color: var(--text-sub);">Create a section first, then you can add fields to it.</p>
                            @else
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap: var(--s-3);">
                                    <div style="grid-column: span 2;">
                                        <label style="font-size: var(--fs-11); color: var(--text-sub); font-weight:500;">Section (where this field goes)</label>
                                        <select wire:model="newFieldSectionId"
                                                style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12); background: var(--surface);">
                                            <option value="">— pick a section —</option>
                                            @foreach ($this->sections() as $section)
                                                <option value="{{ $section->id }}" {{ ($newFieldSectionId ?? $selectedSectionId) == $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('newFieldSectionId') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size: var(--fs-11); color: var(--text-sub); font-weight:500;">Label</label>
                                        <input type="text" wire:model="newFieldLabel" placeholder="e.g. Father's Phone"
                                               style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12); background: var(--surface);" />
                                        @error('label') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                                        @error('newFieldLabel') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size: var(--fs-11); color: var(--text-sub); font-weight:500;">Type</label>
                                        <select wire:model.live="newFieldType"
                                                style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12); background: var(--surface);">
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
                                        <div style="grid-column: span 2;">
                                            <label style="font-size: var(--fs-11); color: var(--text-sub); font-weight:500;">Options (one per line)</label>
                                            <textarea wire:model="newFieldOptionsText" rows="4" placeholder="Option A&#10;Option B&#10;Option C"
                                                      style="width:100%; border:1px solid var(--border); border-radius:var(--r-md); padding:6px 10px; font-size:var(--fs-12); background: var(--surface); font-family:inherit;"></textarea>
                                            @error('newFieldOptionsText') <div style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</div> @enderror
                                        </div>
                                    @endif
                                    <label style="font-size:var(--fs-11); display:flex; align-items:center; gap:6px; color: var(--text);">
                                        <input type="checkbox" wire:model="newFieldRequired" style="accent-color: var(--brand-600);" /> Required on student form
                                    </label>
                                    <label style="font-size:var(--fs-11); display:flex; align-items:center; gap:6px; color: var(--text);">
                                        <input type="checkbox" wire:model="newFieldShowInTable" style="accent-color: var(--brand-600);" /> Show in student list (table)
                                    </label>
                                    <label style="font-size:var(--fs-11); display:flex; align-items:center; gap:6px; color: var(--text);">
                                        <input type="checkbox" wire:model="newFieldShowInKanban" style="accent-color: var(--brand-600);" /> Show on Kanban tile
                                    </label>
                                    <label style="font-size:var(--fs-11); display:flex; align-items:center; gap:6px; color: var(--text);">
                                        <input type="checkbox" wire:model="newFieldShowInImport" style="accent-color: var(--brand-600);" /> Accept in CSV import
                                    </label>
                                </div>
                                <div style="margin-top: var(--s-3); display:flex; align-items:center; gap: var(--s-2);">
                                    <button type="button" wire:click="submitNewField"
                                            style="padding:7px 14px; border-radius: var(--r-md); background: var(--brand-600); color:white; font-size: var(--fs-12); font-weight:600; border:0; cursor:pointer;">
                                        + Add Field
                                    </button>
                                    @error('type') <span style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</span> @enderror
                                    @error('section_id') <span style="font-size:var(--fs-10); color: var(--danger);">{{ $message }}</span> @enderror
                                </div>
                            @endif
                        </div>
                    </main>
                </div>
            @else
                <div class="davya-section-card">
                    <div class="davya-section-card-title">Archived fields</div>
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        @forelse ($this->archivedFields() as $field)
                            <div class="davya-card-row">
                                <span style="flex:1; font-weight:500;">{{ $field->label }}</span>
                                <span style="color: var(--text-sub); font-size: var(--fs-11);">archived {{ $field->archived_at->diffForHumans() }}</span>
                            </div>
                        @empty
                            <div style="font-size: var(--fs-11); color: var(--text-sub);">No archived fields.</div>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>
    @else
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
    @endif
</x-filament-panels::page>
