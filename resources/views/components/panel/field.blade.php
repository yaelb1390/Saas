@props([
    'name',
    'label',
    'type' => 'text',
    'required' => false,
    'value' => null,
    'placeholder' => '',
    'step' => null,
])

<div>
    <label class="bmos-field-label">
        {{ $label }}@if ($required)<span class="text-rose-500">&nbsp;*</span>@endif
    </label>
    <input type="{{ $type }}" name="{{ $name }}"
           value="{{ old($name, $value) }}"
           placeholder="{{ $placeholder }}"
           @if ($step) step="{{ $step }}" @endif
           @if ($required) required @endif
           {{ $attributes->merge(['class' => 'bmos-input']) }}>
</div>
