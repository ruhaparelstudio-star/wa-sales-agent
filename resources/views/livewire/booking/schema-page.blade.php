<div>
    <h1 class="text-xl font-bold mb-4">Booking Schema</h1>

    {{-- Tabs --}}
    <div class="flex gap-1 mb-4 border-b border-gray-200">
        <button wire:click="$set('activeTab', 'inquiry')"
                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px {{ $activeTab === 'inquiry' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500' }}">
            Inquiry Form
        </button>
        <button wire:click="$set('activeTab', 'booking')"
                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px {{ $activeTab === 'booking' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500' }}">
            Booking Form
        </button>
    </div>

    @php
        $fields = $activeTab === 'inquiry' ? $inquiryFields : $bookingFields;
    @endphp

    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-semibold">{{ $activeTab === 'inquiry' ? 'Inquiry' : 'Booking' }} Fields</span>
            <button wire:click="openAddForm" class="text-sm text-indigo-600 hover:underline">+ Add Field</button>
        </div>

        {{-- Add field inline form --}}
        @if($showAddForm)
            <div class="px-5 py-4 bg-indigo-50 border-b border-indigo-100">
                <div class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="text-xs text-gray-500 block mb-1">Label</label>
                        <input wire:model="formLabel" placeholder="Field label"
                               class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 block mb-1">Type</label>
                        <select wire:model="formType" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                            <option value="">Select type</option>
                            @foreach($fieldTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-1.5 text-sm text-gray-600 pb-0.5">
                        <input wire:model="formRequired" type="checkbox" class="rounded">
                        Required
                    </label>
                    <div class="flex gap-2">
                        <button wire:click="addField" class="px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">Add</button>
                        <button wire:click="$set('showAddForm', false)" class="px-3 py-1.5 border border-gray-200 text-sm rounded-lg text-gray-600 hover:bg-gray-50">Cancel</button>
                    </div>
                </div>
            </div>
        @endif

        <div class="divide-y divide-gray-100">
            @forelse($fields as $field)
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        <div class="flex flex-col gap-0.5">
                            <button wire:click="moveUp({{ $field->id }})" class="text-gray-300 hover:text-gray-600 text-xs leading-none">▲</button>
                            <button wire:click="moveDown({{ $field->id }})" class="text-gray-300 hover:text-gray-600 text-xs leading-none">▼</button>
                        </div>
                        <div>
                            <p class="text-sm font-medium">{{ $field->label }}</p>
                            <p class="text-xs text-gray-400">{{ $field->field_type->value }}</p>
                        </div>
                        @if($field->is_required)
                            <span class="text-xs text-red-500">*required</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="toggleRequired({{ $field->id }})"
                                class="text-xs text-gray-400 hover:text-orange-600">
                            {{ $field->is_required ? 'Make Optional' : 'Make Required' }}
                        </button>
                        <button wire:click="deleteField({{ $field->id }})"
                                wire:confirm="Delete this field?"
                                class="text-xs text-red-400 hover:text-red-600">Delete</button>
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-gray-400">
                    No fields yet. Click <strong>Add Field</strong> to start building the form.
                </div>
            @endforelse
        </div>
    </div>
</div>
