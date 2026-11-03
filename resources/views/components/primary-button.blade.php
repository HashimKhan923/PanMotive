<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-accent']) }}>
    {{ $slot }}
</button>
