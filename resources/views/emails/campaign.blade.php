<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f4f6fb;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ $preview }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">
        <tr><td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">
                <tr><td style="padding:20px 28px;border-bottom:1px solid #eef2f7">
                    <span style="font-weight:800;font-size:18px">Pan<span style="color:#F5A524">Motive</span></span>
                </td></tr>
                <tr><td style="padding:28px;font-size:15px;line-height:1.65">
                    {!! $bodyHtml !!}
                </td></tr>
                <tr><td style="padding:18px 28px;border-top:1px solid #eef2f7;font-size:12px;color:#94a3b8">
                    @if ($fromPlatform ?? false)Sent by Pan Motive to people with a Pan Motive account.@else Sent by Pan Motive on behalf of {{ $storeName ?? 'the seller' }}. You're getting this because you bought from or follow this store.@endif
                    @if ($unsubscribeUrl)
                        <br><a href="{{ $unsubscribeUrl }}" style="color:#64748b;text-decoration:underline">Unsubscribe from {{ $storeName ?? 'this store' }}</a>
                        · <a href="{{ route('marketing.preferences') }}" style="color:#64748b;text-decoration:underline">Marketing preferences</a>
                    @endif
                    <br>{{ $address }}
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
