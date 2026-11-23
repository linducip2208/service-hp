<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceLog extends Model
{
    protected $fillable = ['service_ticket_id', 'user_id', 'from_status', 'to_status', 'note'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Path file tanda tangan dari suffix note "[SIG:path]" (tanpa migration). */
    public function signaturePath(): ?string
    {
        if (preg_match('/\[SIG:([^\]]+)\]/', (string) ($this->note ?? ''), $m)) {
            return trim($m[1]) ?: null;
        }
        return null;
    }

    /** Note bersih untuk timeline (suffix [SIG:...] disembunyikan). */
    public function displayNote(): ?string
    {
        $clean = trim((string) preg_replace('/\s*\[SIG:[^\]]+\]/', '', (string) ($this->note ?? '')));
        return $clean === '' ? null : $clean;
    }

    /** Cari log serah terima ber-tanda-tangan terakhir dari koleksi log. */
    public static function handoverSignature($logs): ?string
    {
        try {
            foreach (collect($logs)->sortByDesc('created_at') as $l) {
                if (($l->to_status ?? null) === 'diserahkan' && method_exists($l, 'signaturePath') && $l->signaturePath()) {
                    return $l->signaturePath();
                }
                if (($l->to_status ?? null) === 'diserahkan' && preg_match('/\[SIG:([^\]]+)\]/', (string) ($l->note ?? ''), $m)) {
                    return trim($m[1]) ?: null;
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }
}
