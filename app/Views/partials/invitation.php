<?php /** @var ?array $invitation ['name', 'link', 'whatsapp'] — se muestra una sola vez */ ?>
<?php if (!empty($invitation)): ?>
    <div class="alert alert-info" x-data="{ copied: false }">
        <div class="fw-semibold mb-1">Link de activación para <?= e($invitation['name']) ?></div>
        <div class="small mb-2">Mandáselo por el medio que prefieras. Vence en <?= e(App\Services\UserInvitation::TTL_HOURS) ?> horas y sirve una sola vez. No se vuelve a mostrar.</div>
        <div class="input-group input-group-sm mb-2">
            <input class="form-control font-monospace" value="<?= e($invitation['link']) ?>" readonly x-ref="link" @focus="$el.select()">
            <button class="btn btn-outline-secondary" type="button"
                    @click="navigator.clipboard.writeText($refs.link.value).then(() => copied = true)">
                <span x-text="copied ? 'Copiado ✓' : 'Copiar'">Copiar</span>
            </button>
        </div>
        <a class="btn btn-success btn-sm" href="<?= e($invitation['whatsapp']) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a>
    </div>
<?php endif; ?>
