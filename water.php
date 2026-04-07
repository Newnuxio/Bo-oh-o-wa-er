<?php
/**
 * Water Ripple Effect
 * PHP handles page config and meta — ripple logic runs client-side via Canvas API
 */

$config = [
    'ripple_speed'    => 0.001,
    'ripple_damping'  => 0.985,
    'ripple_strength' => 175,
    'bg_color'        => 'black',
    'page_title'      => 'Water Ripple',
];

$js_config = json_encode($config);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['page_title']) ?></title>

    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; overflow: hidden; background: <?= $config['bg_color'] ?>; font-family: 'Georgia', serif; }
        #rippleCanvas { display: block; width: 100%; height: 100%; cursor: crosshair; }
        .hint {
            position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%);
            color: rgba(180,210,255,0.45); font-size: 13px; letter-spacing: 0.18em;
            text-transform: uppercase; pointer-events: none; user-select: none;
            animation: fadeHint 3s ease 1.5s forwards; opacity: 0.45;
        }
        @keyframes fadeHint { 0% { opacity: 0.45; } 100% { opacity: 0; } }
    </style>
</head>
<body>

<canvas id="rippleCanvas" data-config='<?= $js_config ?>'></canvas>
<p class="hint">Klik of beweeg muis om water te verstoren</p>

<script>
(() => {
    const canvas = document.getElementById('rippleCanvas');
    const cfg    = JSON.parse(canvas.dataset.config);

    const DAMPING  = cfg.ripple_damping;
    const STRENGTH = cfg.ripple_strength;

    const ctx = canvas.getContext('2d');
    let W, H, buf1, buf2, bgImage;

    function resize() {
        canvas.width  = window.innerWidth;
        canvas.height = window.innerHeight;
        W = Math.floor(canvas.width  / 2);
        H = Math.floor(canvas.height / 2);
        buf1 = new Float32Array(W * H);
        buf2 = new Float32Array(W * H);
        drawBackground();
        fish.x = canvas.width  * 0.5;
        fish.y = canvas.height * 0.5;
    }

    function drawBackground() {
        const offscreen = document.createElement('canvas');
        offscreen.width  = canvas.width;
        offscreen.height = canvas.height;
        const oc = offscreen.getContext('2d');
        const grad = oc.createLinearGradient(0, 0, 0, canvas.height);
        grad.addColorStop(0,   '#4B0566');
        grad.addColorStop(0.4, '#792382');
        grad.addColorStop(1,   '#980DDE');
        oc.fillStyle = grad;
        oc.fillRect(0, 0, canvas.width, canvas.height);
        const radial = oc.createRadialGradient(
            canvas.width*0.5, canvas.height*0.3, 10,
            canvas.width*0.5, canvas.height*0.3, canvas.width*0.6
        );
        radial.addColorStop(0, 'rgba(120,190,255,0.12)');
        radial.addColorStop(1, 'rgba(0,0,0,0)');
        oc.fillStyle = radial;
        oc.fillRect(0, 0, canvas.width, canvas.height);
        bgImage = oc.getImageData(0, 0, canvas.width, canvas.height);
    }

    function disturb(x, y, amount) {
        const gx = Math.floor(x / 2);
        const gy = Math.floor(y / 2);
        const radius = 3;
        for (let dy = -radius; dy <= radius; dy++) {
            for (let dx = -radius; dx <= radius; dx++) {
                const nx = gx + dx, ny = gy + dy;
                if (nx >= 0 && nx < W && ny >= 0 && ny < H) {
                    const dist = Math.sqrt(dx*dx + dy*dy);
                    if (dist <= radius) buf1[ny * W + nx] += amount * (1 - dist / radius);
                }
            }
        }
    }

    function simulate() {
        for (let y = 1; y < H - 1; y++) {
            for (let x = 1; x < W - 1; x++) {
                const i = y * W + x;
                const avg = (buf1[(y-1)*W+x] + buf1[(y+1)*W+x] + buf1[y*W+(x-1)] + buf1[y*W+(x+1)]) * 0.5;
                buf2[i] = (avg - buf2[i]) * DAMPING;
            }
        }
        [buf1, buf2] = [buf2, buf1];
    }

    function render() {
        const output = ctx.createImageData(canvas.width, canvas.height);
        const src = bgImage.data, dst = output.data;
        const cw = canvas.width, ch = canvas.height;
        for (let y = 1; y < H - 1; y++) {
            for (let x = 1; x < W - 1; x++) {
                const i = y * W + x;
                const dx = (buf1[i+1] - buf1[i-1]) * STRENGTH * 0.5;
                const dy = (buf1[i+W] - buf1[i-W]) * STRENGTH * 0.5;
                const screenX = x * 2, screenY = y * 2;
                for (let py = 0; py < 2; py++) {
                    for (let px = 0; px < 2; px++) {
                        const sx = screenX + px, sy = screenY + py;
                        const srcX = Math.max(0, Math.min(cw-1, sx + dx | 0));
                        const srcY = Math.max(0, Math.min(ch-1, sy + dy | 0));
                        const dstIdx = (sy * cw + sx) * 4;
                        const srcIdx = (srcY * cw + srcX) * 4;
                        dst[dstIdx]   = src[srcIdx];
                        dst[dstIdx+1] = src[srcIdx+1];
                        dst[dstIdx+2] = src[srcIdx+2];
                        dst[dstIdx+3] = 255;
                    }
                }
            }
        }
        ctx.putImageData(output, 0, 0);
    }

    /* ── Ripple sensing ──────────────────────────────────────────────────────
       Sample a neighbourhood in the ripple buffer around the fish.
       Returns total local amplitude and a gradient vector pointing toward the
       most disturbed region — the fish flees in the opposite direction.
    ──────────────────────────────────────────────────────────────────────── */
    function senseRipple(px, py) {
        const gx = Math.floor(px / 2);
        const gy = Math.floor(py / 2);
        if (gx < 4 || gx >= W - 4 || gy < 4 || gy >= H - 4) return { amp: 0, fx: 0, fy: 0 };

        const SENSE = 7;   /* half-width of sensing neighbourhood in buffer cells */
        let amp = 0, fx = 0, fy = 0;

        for (let dy = -SENSE; dy <= SENSE; dy++) {
            for (let dx = -SENSE; dx <= SENSE; dx++) {
                const nx = gx + dx, ny = gy + dy;
                if (nx < 1 || nx >= W-1 || ny < 1 || ny >= H-1) continue;
                const v = Math.abs(buf1[ny * W + nx]);
                amp += v;
                /* weight gradient by amplitude and inverse distance so nearby
                   disturbances count more than distant ones                  */
                const w = v / (dx*dx + dy*dy + 1);
                fx += dx * w;
                fy += dy * w;
            }
        }
        return { amp, fx, fy };
    }

    /* ── Fish ─────────────────────────────────────────────────────────────── */

    const FEAR_THRESHOLD = 18;
    const FISH_MAX_SPEED = 9;
    const FISH_FRICTION  = 0.88;
    const WANDER_FORCE   = 0.04;

    const fish = {
        x: 0, y: 0,
        vx: 0.5, vy: 0.3,
        angle: 0,
        wanderAngle: Math.random() * Math.PI * 2,
        scared: false,
        scaredTimer: 0,
    };

    function updateFish() {
        const { amp, fx, fy } = senseRipple(fish.x, fish.y);

        if (amp > FEAR_THRESHOLD) fish.scaredTimer = 18;
        if (fish.scaredTimer > 0) fish.scaredTimer--;
        fish.scared = fish.scaredTimer > 0;

        if (fish.scared) {
            /* flee opposite to the gradient (away from disturbance) */
            const mag = Math.sqrt(fx*fx + fy*fy);
            if (mag > 0) {
                const intensity = Math.min(amp / 120, 1);
                fish.vx -= (fx / mag) * intensity * 3.4;
                fish.vy -= (fy / mag) * intensity * 3.4;
            }
        } else {
            fish.wanderAngle += (Math.random() - 0.5) * 0.25;
            fish.vx += Math.cos(fish.wanderAngle) * WANDER_FORCE;
            fish.vy += Math.sin(fish.wanderAngle) * WANDER_FORCE;
        }

        const speed  = Math.sqrt(fish.vx*fish.vx + fish.vy*fish.vy);
        const maxSpd = fish.scared ? FISH_MAX_SPEED : 2.0;
        if (speed > maxSpd) { fish.vx = (fish.vx/speed)*maxSpd; fish.vy = (fish.vy/speed)*maxSpd; }

        fish.vx *= FISH_FRICTION;
        fish.vy *= FISH_FRICTION;
        fish.x  += fish.vx;
        fish.y  += fish.vy;

        const M = 40;
        if (fish.x < -M) fish.x = canvas.width  + M;
        if (fish.x > canvas.width  + M) fish.x = -M;
        if (fish.y < -M) fish.y = canvas.height + M;
        if (fish.y > canvas.height + M) fish.y = -M;

        if (speed > 0.15) {
            const target = Math.atan2(fish.vy, fish.vx);
            let diff = target - fish.angle;
            while (diff >  Math.PI) diff -= Math.PI * 2;
            while (diff < -Math.PI) diff += Math.PI * 2;
            fish.angle += diff * 0.18;
        }
    }

    function drawFish() {
        const now   = Date.now();
        const speed = Math.sqrt(fish.vx*fish.vx + fish.vy*fish.vy);
        const wagFreq = fish.scared ? 0.018 : 0.008;
        const wagAmp  = fish.scared ? 0.38 + speed*0.028 : 0.14 + speed*0.02;
        const wag     = Math.sin(now * wagFreq) * wagAmp;

        const bodyColor = fish.scared ? 'rgba(255,210,100,0.90)' : 'rgba(130,230,255,0.88)';
        const finColor  = fish.scared ? 'rgba(220,150, 40,0.82)' : 'rgba( 70,170,230,0.82)';
        const tailColor = fish.scared ? 'rgba(200,120, 30,0.85)' : 'rgba( 60,150,210,0.85)';

        ctx.save();
        ctx.translate(fish.x, fish.y);
        ctx.rotate(fish.angle);

        /* tail */
        ctx.save();
        ctx.translate(-30, 0);
        ctx.rotate(wag);
        ctx.beginPath();
        ctx.moveTo(0,0); ctx.lineTo(-22,-14); ctx.lineTo(-16,0); ctx.lineTo(-22,14);
        ctx.closePath();
        ctx.fillStyle = tailColor;
        ctx.fill();
        ctx.restore();

        /* dorsal fin */
        ctx.save();
        ctx.translate(4, 0);
        ctx.beginPath();
        ctx.moveTo(-8,-13); ctx.quadraticCurveTo(2,-24,14,-13);
        ctx.closePath();
        ctx.fillStyle = finColor;
        ctx.fill();
        ctx.restore();

        /* pectoral fin */
        ctx.save();
        ctx.translate(2, 8); ctx.rotate(0.3);
        ctx.beginPath();
        ctx.ellipse(0, 0, 10, 5, 0, 0, Math.PI*2);
        ctx.fillStyle = finColor;
        ctx.fill();
        ctx.restore();

        /* body */
        ctx.beginPath();
        ctx.ellipse(0, 0, 30, 14, 0, 0, Math.PI*2);
        ctx.fillStyle = bodyColor;
        ctx.fill();

        /* scales */
        ctx.strokeStyle = 'rgba(255,255,255,0.18)';
        ctx.lineWidth = 1;
        for (let s = 0; s < 3; s++) {
            ctx.beginPath();
            ctx.arc(-10 + s*10, 0, 9, Math.PI*0.2, Math.PI*0.8);
            ctx.stroke();
        }

        /* eye */
        ctx.beginPath(); ctx.arc(17,-4,5,0,Math.PI*2);
        ctx.fillStyle = 'rgba(255,255,255,0.95)'; ctx.fill();
        const pupilOff = fish.scared ? -1.5 : 1;
        ctx.beginPath(); ctx.arc(17+pupilOff,-4,2.5,0,Math.PI*2);
        ctx.fillStyle = '#1a1a2e'; ctx.fill();
        ctx.beginPath(); ctx.arc(18.5+pupilOff,-5.5,1,0,Math.PI*2);
        ctx.fillStyle = 'rgba(255,255,255,0.8)'; ctx.fill();

        ctx.restore();
    }

    /* ── Loop ────────────────────────────────────────────────── */
    function loop() {
        simulate();
        render();
        updateFish();
        drawFish();
        requestAnimationFrame(loop);
    }

    /* ── Input ───────────────────────────────────────────────── */
    canvas.addEventListener('click', (e) => { disturb(e.clientX, e.clientY, STRENGTH); });
    canvas.addEventListener('mouseclick', (e) => {
        if (e.buttons === 1) disturb(e.clientX, e.clientY, STRENGTH * 0.5);
        else disturb(e.clientX, e.clientY, STRENGTH * 0.06);
    });
    canvas.addEventListener('touchmove', (e) => {
        e.preventDefault();
        const t = e.touches[0]; disturb(t.clientX, t.clientY, STRENGTH * 0.5);
    }, { passive: false });
    canvas.addEventListener('touchstart', (e) => {
        const t = e.touches[0]; disturb(t.clientX, t.clientY, STRENGTH);
    });

    /* ── Ambient drops ───────────────────────────────────────── */
    function ambientDrop() {
        disturb(Math.random() * canvas.width, Math.random() * canvas.height, STRENGTH * 0.9);
        setTimeout(ambientDrop, 1500 + Math.random() * 3000);
    }

    window.addEventListener('resize', resize);
    resize();
    ambientDrop();
    loop();
})();
</script>

</body>
</html>