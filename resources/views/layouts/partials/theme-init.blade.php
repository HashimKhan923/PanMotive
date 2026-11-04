{{-- The server already sets the class from the pm-theme cookie, so a refresh never paints the wrong theme.
     This corrects it from the saved choice and keeps the cookie current. data-cfasync stops Cloudflare Rocket Loader delaying it. --}}
<script data-cfasync="false" @nonce>
    try {
        var t = localStorage.getItem('pm-theme');
        var d = t ? t === 'dark' : {!! $defaultDark ? 'true' : "matchMedia('(prefers-color-scheme: dark)').matches" !!};
        document.documentElement.classList.toggle('dark', d);
        document.cookie = 'pm-theme=' + (d ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax';
    } catch (e) {}
</script>
