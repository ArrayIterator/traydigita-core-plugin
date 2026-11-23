#!/usr/bin/env python3
"""TrayDigita Core logo generator v7 — theme: "Connecting To The Core".

Keeps the REAL TrayDigita mark (white T+D glyph from the theme repo).
The mark is THREE separate white pieces (its three potrace subpaths):
  piece 0 — stem        (x 143-215, y 162-414)
  piece 1 — bowl        (x 224-421, y 178-414)
  piece 2 — top swoosh  (x  78-423, y  86-237)
v7 direction (user, voice call 2026-11-23 + reference study): v3-v6 were
all rejected as "kampungan" (crude). The motion language combines TWO
references — STYLE only, never their shapes:
  - Cake (Alex Gorbunov, Pinterest — PRIMARY): a shape spins/flows
    abstractly into a letter, then letters enter ONE BY ONE, each
    landing with a soft bounce/settle, then the logo holds still.
  - Handsign / signature (Pinterest pin 5277724556004619): ONE flowing
    stroke draws the letters like a signature being written — smooth,
    one continuous flow, no tumbling.
v7 story (5 s, 24 fps): the three white pieces FLOW like signature
strokes — each swings gently into alignment (small controlled rotation,
blurred at first, like ink coming into focus), draws itself along its
own path, then settles with a tiny soft bounce. No tumbling, no random
spinning, no pieces flying in from three directions, no small-to-big
growth, NO dots / rings / nodes / chips / networks anywhere — the only
leader is a soft glow at the stroke's leading edge.
  1. DRAW (0.15-2.80 s): swoosh draws left-to-right (0.15-1.20 s), then
     the bowl draws along its arc (1.15-2.15 s), then the stem draws
     top-to-bottom (2.10-2.80 s). Each stroke is a clip-wipe following
     the piece's own geometry (the bowl uses an annulus-sector clip so
     it grows as a curved band, not a wedge spike).
  2. FLASH (2.80-3.40 s): soft gold glow (no hard disc) as the logo
     resolves.
  3. HOLD (2.80-5.0 s): clean whole mark on the gradient tile, nothing
     else — just the sheen breathing gently (~2.2 s hold).
Outputs: traydigita-core-logo.mp4 (video), traydigita-core-logo.gif,
traydigita-core-logo.svg (animated SMIL), traydigita-core-logo-static.svg,
icon-512/256/128.png, banner-1544x500.png + banner-772x250.png.

Frames are baked in Python (no SMIL timing in the video) and screenshotted
with chrome-headless-shell, then assembled with ffmpeg.
"""
import math
import os
import re
import subprocess

CHROME = os.path.expanduser(
    "~/.cache/ms-playwright/chromium_headless_shell-1255/"
    "chrome-headless-shell-linux64/chrome-headless-shell"
)
BRAND = os.path.dirname(os.path.abspath(__file__))
TMP = "/tmp/td-core-brand7"
os.makedirs(TMP, exist_ok=True)
FPS = 24
DURATION = 5.0
N_FRAMES = int(FPS * DURATION)

MARK_SRC = os.path.expanduser(
    "~/workspace/traydigita-theme-build/resources/images/traydigita-mark.svg"
)
with open(MARK_SRC) as f:
    MARK_D = re.search(r'<path[^>]+d="([^"]+)"', f.read()).group(1)

_SUBS = re.findall(r"M[^M]+", MARK_D)
# Tile space = mark space + 6 (the frame svg translates the mark by (6,6)).
# Draw strokes, one by one, like a signature:
#   swoosh: left -> right along the top arc (pen tip follows a bezier)
#   bowl:   along its arc, top-left -> right -> bottom (sector clip)
#   stem:   top -> bottom (vertical wipe)
# (d, kind, begin_s, dur_s)
PIECES = [
    (_SUBS[2], "swoosh", 0.15, 1.05),
    (_SUBS[1], "bowl", 1.15, 1.00),
    (_SUBS[0], "stem", 2.10, 0.70),
]
FLASH_BEGIN, FLASH_DUR = 2.80, 0.60

# Cake-style entry: each piece swings gently into alignment around its
# own centre while it draws (small controlled rotation, never a
# somersault), blurred at first like ink coming into focus, then settles
# with a tiny soft bounce once drawn.
CENTERS = {"swoosh": (256.0, 167.0), "bowl": (296.0, 302.0),
           "stem": (185.0, 294.0)}
SETTLE_DUR = 0.25

# Bowl arc geometry (tile space): the bowl is a ~180 deg arc around
# centre (296, 302); the pen tip rides its midline (r=128), the clip
# sector uses r=200 so it covers the whole bowl.
BOWL_CX, BOWL_CY = 296.0, 302.0
BOWL_PHI0, BOWL_PHI1 = -20.0, 220.0  # degrees, clockwise from straight up
BOWL_TIP_R, BOWL_CLIP_R, BOWL_INNER_R = 128.0, 200.0, 40.0


def clamp(x, lo=0.0, hi=1.0):
    return max(lo, min(hi, x))


def smoothstep(p):
    p = clamp(p)
    return p * p * (3 - 2 * p)


def tile_defs():
    return (
        "<defs>"
        '<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
        '<stop offset="0" stop-color="#3e557f"/>'
        '<stop offset="0.45" stop-color="#2563eb"/>'
        '<stop offset="0.75" stop-color="#0891b2"/>'
        '<stop offset="1" stop-color="#0B6B3A"/>'
        "</linearGradient>"
        '<radialGradient id="sheen" cx="0.3" cy="0.22" r="0.9">'
        '<stop offset="0" stop-color="#ffffff" stop-opacity="0.20"/>'
        '<stop offset="0.5" stop-color="#ffffff" stop-opacity="0"/>'
        "</radialGradient>"
        '<radialGradient id="glow" cx="0.5" cy="0.5" r="0.5">'
        '<stop offset="0" stop-color="#ffffff" stop-opacity="0.85"/>'
        '<stop offset="0.6" stop-color="#bae6fd" stop-opacity="0.25"/>'
        '<stop offset="1" stop-color="#ffffff" stop-opacity="0"/>'
        "</radialGradient>"
        '<radialGradient id="tipglow" cx="0.5" cy="0.5" r="0.5">'
        '<stop offset="0" stop-color="#ffffff" stop-opacity="0.9"/>'
        '<stop offset="0.4" stop-color="#ffffff" stop-opacity="0.35"/>'
        '<stop offset="1" stop-color="#ffffff" stop-opacity="0"/>'
        "</radialGradient>"
        '<radialGradient id="goldglow" cx="0.5" cy="0.5" r="0.5">'
        '<stop offset="0" stop-color="#fbbf24" stop-opacity="0.85"/>'
        '<stop offset="0.5" stop-color="#fbbf24" stop-opacity="0.25"/>'
        '<stop offset="1" stop-color="#fbbf24" stop-opacity="0"/>'
        "</radialGradient>"
        '<filter id="fflur" x="-20%" y="-20%" width="140%" height="140%">'
        '<feGaussianBlur stdDeviation="5"/>'
        "</filter>"
        "</defs>"
    )


def _bez(p, a, b, c):
    """Quadratic bezier point at parameter p."""
    q = 1 - p
    return (q * q * a[0] + 2 * q * p * b[0] + p * p * c[0],
            q * q * a[1] + 2 * q * p * b[1] + p * p * c[1])


def _rot(x, y, cx, cy, deg):
    """Rotate point (x, y) around (cx, cy) by deg degrees (SVG, y down)."""
    r = math.radians(deg)
    dx, dy = x - cx, y - cy
    return (cx + dx * math.cos(r) - dy * math.sin(r),
            cy + dx * math.sin(r) + dy * math.cos(r))


def piece_motion(t, kind, begin, dur, p):
    """Cake-style entry for one piece: (angle_deg, blur_px, opacity).

    While drawing it swings gently into alignment (-12 deg -> 0 deg,
    blurred like ink coming into focus); once drawn it settles with a
    tiny soft bounce (a 2 deg wobble over SETTLE_DUR). Never a
    somersault, never a scale change.
    """
    end = begin + dur
    if p < 1:
        s = smoothstep(p)
        ang = -12.0 * (1.0 - s)
        blur = 6.0 * (1.0 - smoothstep(p / 0.6))
        op = 0.65 + 0.35 * s
    elif t < end + SETTLE_DUR:
        q = (t - end) / SETTLE_DUR
        ang = 2.0 * math.sin(math.pi * q)
        blur, op = 0.0, 1.0
    else:
        ang, blur, op = 0.0, 0.0, 1.0
    return ang, blur, op


def piece_state(t, kind, begin, dur):
    """Draw progress p in [0,1], clip geometry and pen-tip position.

    Returns (p, clip_svg, tip) where clip_svg is the <clipPath> content
    for this piece at time t (empty string when fully open/closed) and
    tip is (x, y, opacity) of the pen tip, or None when no tip shows.
    All coordinates are in tile space.
    """
    p = smoothstep((t - begin) / dur)
    if p <= 0:
        return 0.0, None, None
    if kind == "swoosh":
        # Pen tip rides a bezier over the top arc; the clip rect's
        # leading edge tracks the tip's x exactly.
        tip = _bez(p, (84, 150), (256, 70), (435, 220))
        w = tip[0] - 64
        clip = (f'<clipPath id="c-swoosh"><rect x="64" y="72" '
                f'width="{w:.1f}" height="191"/></clipPath>')
    elif kind == "stem":
        # Vertical wipe, top -> bottom; tip rides the leading edge.
        tip = (185.0, 168.0 + 252.0 * p)
        h = tip[1] - 148
        clip = (f'<clipPath id="c-stem"><rect x="129" y="148" '
                f'width="112" height="{h:.1f}"/></clipPath>')
    else:  # bowl — annulus-sector clip sweeping along the arc, tip on the
        # arc. The inner radius keeps the clip a curved band (no wedge
        # spike at the centre); the 240 deg sweep covers the bowl's
        # bottom-left tip (phi ~217 deg) so nothing pops in at the end.
        phi = math.radians(BOWL_PHI0 + (BOWL_PHI1 - BOWL_PHI0) * p)
        tip = (BOWL_CX + BOWL_TIP_R * math.sin(phi),
               BOWL_CY - BOWL_TIP_R * math.cos(phi))
        phi0 = math.radians(BOWL_PHI0)
        xo0 = BOWL_CX + BOWL_CLIP_R * math.sin(phi0)
        yo0 = BOWL_CY - BOWL_CLIP_R * math.cos(phi0)
        xo1 = BOWL_CX + BOWL_CLIP_R * math.sin(phi)
        yo1 = BOWL_CY - BOWL_CLIP_R * math.cos(phi)
        xi1 = BOWL_CX + BOWL_INNER_R * math.sin(phi)
        yi1 = BOWL_CY - BOWL_INNER_R * math.cos(phi)
        xi0 = BOWL_CX + BOWL_INNER_R * math.sin(phi0)
        yi0 = BOWL_CY - BOWL_INNER_R * math.cos(phi0)
        clip = (f'<clipPath id="c-bowl"><path d="M {xo0:.1f} {yo0:.1f} '
                f'A {BOWL_CLIP_R:.0f} {BOWL_CLIP_R:.0f} 0 1 1 {xo1:.1f} {yo1:.1f} '
                f'L {xi1:.1f} {yi1:.1f} '
                f'A {BOWL_INNER_R:.0f} {BOWL_INNER_R:.0f} 0 1 0 {xi0:.1f} {yi0:.1f} '
                'Z"/></clipPath>')
    # Pen tip fades in at the stroke start, out at its end (pen lift).
    tip_op = clamp(p / 0.05) * (1 - clamp((p - 0.92) / 0.08))
    tip = (tip[0], tip[1], tip_op) if tip_op > 0.01 else None
    return p, clip, tip


def frame_svg(t):
    p = [
        '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" '
        'viewBox="0 0 512 512">',
        tile_defs(),
        '<rect x="8" y="8" width="496" height="496" rx="112" fill="url(#bg)"/>',
        '<rect x="8" y="8" width="496" height="496" rx="112" fill="url(#sheen)" '
        f'opacity="{0.75 + 0.25 * math.sin(2 * math.pi * t / 4.0):.3f}"/>',
    ]
    # Soft glow peaking as the logo resolves.
    glow = 0.30 * math.sin(math.pi * clamp((t - 2.1) / 1.1))
    if glow > 0.005:
        p.append(
            f'<circle cx="256" cy="256" r="190" fill="url(#glow)" '
            f'opacity="{glow:.3f}"/>'
        )
    # Opening flourish — a soft, blurred ribbon sweeping in like the
    # flourish of a signature, before the first stroke forms. This is
    # the abstract opening: flowing water, not letters; it dissolves
    # into the swoosh as that stroke begins.
    if t < 0.75:
        fq = clamp(t / 0.55)
        fop = 0.5 * math.sin(math.pi * clamp(t / 0.75))
        if fop > 0.01:
            p.append(
                f'<g opacity="{fop:.3f}" transform="translate({18.0 * fq:.1f},0)">'
                '<path d="M 60 215 C 170 130, 320 115, 445 195" fill="none" '
                'stroke="#ffffff" stroke-width="13" stroke-linecap="round" '
                'filter="url(#fflur)"/></g>'
            )
    # The three pieces, drawn one by one. Each piece is clipped by its
    # own draw wipe (signature stroke) and swings gently into alignment
    # while it draws; the leading edge carries only a soft glow — no
    # dot, no ring.
    for d, kind, begin, dur in PIECES:
        prog, clip, tip = piece_state(t, kind, begin, dur)
        if prog <= 0:
            continue
        ang, blur, op = piece_motion(t, kind, begin, dur, prog)
        cx, cy = CENTERS[kind]
        attrs = (f'transform="rotate({ang:.2f} {cx:.0f} {cy:.0f})" '
                 f'opacity="{op:.3f}"')
        if blur > 0.3:
            p.append(
                f'<defs><filter id="f-{kind}" x="-20%" y="-20%" width="140%" '
                f'height="140%"><feGaussianBlur stdDeviation="{blur:.1f}"/>'
                "</filter></defs>"
            )
            attrs += f' filter="url(#f-{kind})"'
        if clip is not None and prog < 1:
            p.append("<defs>" + clip + "</defs>")
            p.append(
                f'<g {attrs}><g clip-path="url(#c-{kind})" '
                f'transform="translate(6,6)">'
                f'<path d="{d}" fill="#ffffff"/></g></g>'
            )
        else:
            p.append(
                f'<g {attrs}><g transform="translate(6,6)">'
                f'<path d="{d}" fill="#ffffff"/></g></g>'
            )
        if tip is not None:
            x, y, top = tip
            xr, yr = _rot(x, y, cx, cy, ang)
            p.append(
                f'<circle cx="{xr:.1f}" cy="{yr:.1f}" r="30" '
                f'fill="url(#tipglow)" opacity="{0.55 * top:.3f}"/>'
            )
    # Gold flash at the seam as the logo resolves — a soft glow, never a
    # hard disc (a disc would read as a dot).
    if FLASH_BEGIN <= t <= FLASH_BEGIN + FLASH_DUR:
        ph = (t - FLASH_BEGIN) / FLASH_DUR
        s = math.sin(math.pi * ph)
        p.append(
            f'<circle cx="256" cy="250" r="130" fill="url(#goldglow)" '
            f'opacity="{0.55 * s:.3f}"/>'
        )
    p.append("</svg>")
    return "".join(p)


def mark_svg():
    return f'<g transform="translate(6,6)"><path d="{MARK_D}" fill="#ffffff"/></g>'


def build_svg_animated():
    """SMIL approximation of the baked video: rect wipes + soft-glow tips
    riding the same paths via animateMotion. The video is the accurate
    version; the SMIL omits the swing/blur entry and the bowl's
    annulus-sector clip (rect wipe instead)."""
    p = [
        '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" '
        'viewBox="0 0 512 512">',
        tile_defs(),
        '<rect x="8" y="8" width="496" height="496" rx="112" fill="url(#bg)"/>',
        '<rect x="8" y="8" width="496" height="496" rx="112" fill="url(#sheen)" '
        'opacity="0.85">'
        '<animate attributeName="opacity" values="0.85;1;0.85" dur="4s" '
        'repeatCount="indefinite"/>'
        "</rect>",
        # Glow peaking as the logo resolves.
        '<circle cx="256" cy="256" r="190" fill="url(#glow)" opacity="0">'
        '<animate attributeName="opacity" values="0;0.3;0" '
        'keyTimes="0;0.5;1" begin="2.1s" dur="1.1s" fill="freeze"/>'
        "</circle>",
        # Clip wipes (rect approximations; the bowl's sector is a
        # top-to-bottom wipe here).
        "<defs>"
        '<clipPath id="c-swoosh"><rect x="64" y="72" width="0" height="191">'
        '<animate attributeName="width" values="0;371" begin="0.15s" '
        'dur="1.05s" fill="freeze"/></rect></clipPath>'
        '<clipPath id="c-bowl"><rect x="204" y="158" width="245" height="0">'
        '<animate attributeName="height" values="0;282" begin="1.15s" '
        'dur="1s" fill="freeze"/></rect></clipPath>'
        '<clipPath id="c-stem"><rect x="129" y="148" width="112" height="0">'
        '<animate attributeName="height" values="0;272" begin="2.10s" '
        'dur="0.7s" fill="freeze"/></rect></clipPath>'
        "</defs>",
    ]
    # The pieces, revealed by their wipes.
    for d, kind, _begin, _dur in PIECES:
        p.append(
            '<g clip-path="url(#c-' + kind + ')" transform="translate(6,6)">'
            f'<path d="{d}" fill="#ffffff"/></g>'
        )
    # Pen tips riding the draw paths — soft glow only, no dot, no ring.
    tips = [
        ("M84 150 Q256 70 435 220", 0.15, 1.05),   # swoosh bezier
        ("M252 180 A128 128 0 0 1 340 419", 1.15, 1.00),  # bowl arc
        ("M185 168 L185 420", 2.10, 0.70),          # stem line
    ]
    for path, begin, dur in tips:
        p.append(
            '<circle r="26" fill="url(#tipglow)" opacity="0">'
            f'<animateMotion dur="{dur}s" begin="{begin:.2f}s" '
            f'fill="freeze" path="{path}"/>'
            '<animate attributeName="opacity" values="0;0.55;0.55;0" '
            f'keyTimes="0;0.05;0.92;1" begin="{begin:.2f}s" dur="{dur}s" '
            'fill="freeze"/>'
            "</circle>"
        )
    # Gold flash at the seam — soft glow, never a hard disc.
    p.append(
        '<circle cx="256" cy="250" r="130" fill="url(#goldglow)" opacity="0">'
        '<animate attributeName="opacity" values="0;0.55;0" '
        f'begin="{FLASH_BEGIN}s" dur="{FLASH_DUR}s" fill="freeze"/>'
        "</circle>"
    )
    p.append("</svg>")
    return "".join(p)


def shot(svg_path, png_path, width, height):
    subprocess.run(
        [CHROME, "--headless", "--disable-gpu", "--no-sandbox",
         f"--screenshot={png_path}", f"--window-size={width},{height}",
         "--hide-scrollbars", f"file://{svg_path}"],
        check=True, capture_output=True, timeout=60,
    )


def main():
    import sys

    mode = sys.argv[1] if len(sys.argv) > 1 else "full"
    if mode in ("probes", "full"):
        for t, name in [(0.25, "probe-open"), (0.7, "probe-draw"),
                        (1.6, "probe-bowl"), (2.4, "probe-stem"),
                        (3.1, "probe-flash"), (4.5, "probe-end")]:
            svg = f"{TMP}/{name}.svg"
            with open(svg, "w") as f:
                f.write(frame_svg(t))
            shot(svg, f"{TMP}/{name}.png", 512, 512)
        print("probes done")
    if mode == "probes":
        return
    for i in range(N_FRAMES):
        svg = f"{TMP}/frame-{i:03d}.svg"
        with open(svg, "w") as f:
            f.write(frame_svg(i / FPS))
        shot(svg, f"{TMP}/frame-{i:03d}.png", 512, 512)
    subprocess.run(
        ["ffmpeg", "-y", "-v", "error", "-framerate", str(FPS),
         "-i", f"{TMP}/frame-%03d.png", "-c:v", "libx264", "-pix_fmt",
         "yuv420p", "-movflags", "+faststart",
         f"{BRAND}/traydigita-core-logo.mp4"],
        check=True, timeout=180,
    )
    from PIL import Image

    gif_frames = [Image.open(f"{TMP}/frame-{i:03d}.png").convert("RGB")
                  for i in range(0, N_FRAMES, 4)]
    gif_frames[0].save(
        f"{BRAND}/traydigita-core-logo.gif", save_all=True,
        append_images=gif_frames[1:], duration=167, loop=0, optimize=True,
    )
    with open(f"{BRAND}/traydigita-core-logo.svg", "w") as f:
        f.write(build_svg_animated())
    with open(f"{BRAND}/traydigita-core-logo-static.svg", "w") as f:
        f.write(frame_svg(DURATION))
    with open(f"{TMP}/static.svg", "w") as f:
        f.write(frame_svg(DURATION))
    shot(f"{TMP}/static.svg", f"{BRAND}/icon-512x512.png", 512, 512)
    base = Image.open(f"{BRAND}/icon-512x512.png").convert("RGB")
    base.resize((256, 256), Image.LANCZOS).save(f"{BRAND}/icon-256x256.png")
    base.resize((128, 128), Image.LANCZOS).save(f"{BRAND}/icon-128x128.png")
    banner_svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" width="1544" height="500" '
        'viewBox="0 0 1544 500">'
        "<defs>"
        '<linearGradient id="bbg" x1="0" y1="0" x2="1" y2="0">'
        '<stop offset="0" stop-color="#1e3a8a"/>'
        '<stop offset="0.5" stop-color="#1d4ed8"/>'
        '<stop offset="1" stop-color="#0B6B3A"/>'
        "</linearGradient>"
        "</defs>"
        '<rect width="1544" height="500" fill="url(#bbg)"/>'
        '<g transform="translate(71,71) scale(0.7)">'
        + mark_svg()
        + "</g>"
        '<text x="520" y="215" font-family="Arial, Helvetica, sans-serif" '
        'font-size="104" font-weight="800" fill="#ffffff">TrayDigita</text>'
        '<text x="522" y="305" font-family="Arial, Helvetica, sans-serif" '
        'font-size="52" font-weight="700" fill="#fbbf24" letter-spacing="14">'
        "CORE</text>"
        '<text x="522" y="380" font-family="Arial, Helvetica, sans-serif" '
        'font-size="34" font-weight="400" fill="#dbeafe">'
        "Connecting To The Core</text>"
        "</svg>"
    )
    with open(f"{TMP}/banner.svg", "w") as f:
        f.write(banner_svg)
    shot(f"{TMP}/banner.svg", f"{BRAND}/banner-1544x500.png", 1544, 500)
    b = Image.open(f"{BRAND}/banner-1544x500.png").convert("RGB")
    b.resize((772, 250), Image.LANCZOS).save(f"{BRAND}/banner-772x250.png")
    print("brand assets v7 written to", BRAND)
    for name in sorted(os.listdir(BRAND)):
        p = os.path.join(BRAND, name)
        if os.path.isfile(p):
            print(f"  {name}: {os.path.getsize(p)} bytes")


if __name__ == "__main__":
    main()
