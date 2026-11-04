<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#F5F6F8;font-family:Inter,Arial,sans-serif;color:#15191E">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ $preview }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">
        <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #D9DDE3">
                <tr><td style="padding:20px 28px;border-bottom:3px solid #E51B2B">
                    <img src="{{ asset('images/logo/Panmotive-primary-fullcolor.png') }}" alt="Panmotive" width="180" height="26" style="display:block;border:0;height:26px;width:180px">
                </td></tr>
                <tr><td style="padding:28px;font-size:16px;line-height:24px">
                    {!! $bodyHtml !!}
                </td></tr>
                <tr><td style="padding:18px 28px;border-top:1px solid #D9DDE3;font-size:12px;line-height:16px;color:#5E6672">
                    @if ($fromPlatform ?? false)Sent by Panmotive to people with a Panmotive account.@else Sent by Panmotive on behalf of {{ $storeName ?? 'the seller' }}. You're getting this because you bought from or follow this store.@endif
                    @if ($unsubscribeUrl)
                        <br><a href="{{ $unsubscribeUrl }}" style="color:#5E6672;text-decoration:underline">Unsubscribe from {{ $storeName ?? 'this store' }}</a>
                        · <a href="{{ route('marketing.preferences') }}" style="color:#5E6672;text-decoration:underline">Marketing preferences</a>
                    @endif
                    <br>{{ $address }}
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
