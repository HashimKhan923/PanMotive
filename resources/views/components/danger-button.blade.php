<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn bg-rose-600 text-white hover:bg-rose-500 focus-visible:outline-rose-500']) }}>
    {{ $slot }}
</button>
