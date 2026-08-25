@props([
    'letter',
    'center' => false,
    'disabled' => false,
])

{{--
    A real SVG hexagon, not a clip-path'd box (docs/concerns.md) --
    clip-path only clips whatever border the box already had drawn along
    its own *rectangular* edges (top/right/bottom/left straight lines), it
    doesn't draw a border along the new diagonal edges the clip itself
    introduces. That left only the vertical left/right edges of each hex
    with a visible line at all, and nothing along the 4 diagonal ones --
    reading as missing borders and overlapping/bleeding-together cells at
    every diagonal seam. An SVG <polygon> stroke draws an actual line
    along all 6 edges regardless of orientation, so two touching hexes'
    coincident shared edge now genuinely renders a border on both sides
    of it, not a silent gap. Points match the polygon this replaced
    exactly (0%/25%/75%/100% split), just in a 100x115.47 viewBox instead
    of percentages, so the layout math in app.css's own .hive rules
    (row centering, the -0.25*height row overlap) is untouched.
--}}
<button type="button" {{ $attributes->merge(['class' => 'hive-hex']) }} @disabled($disabled)>
    <svg viewBox="0 0 100 115.47" class="hive-hex-svg" aria-hidden="true" focusable="false">
        <polygon points="50,0 100,28.87 100,86.6 50,115.47 0,86.6 0,28.87"
            style="fill: {{ $center ? 'color-mix(in oklch, var(--accent) 18%, var(--die))' : 'var(--die)' }}; stroke: {{ $center ? 'var(--accent)' : 'var(--die-border)' }};" />
    </svg>
    <span class="hive-hex-label" style="color: {{ $center ? 'var(--accent)' : 'var(--ink)' }};">{{ strtoupper($letter) }}</span>
</button>
