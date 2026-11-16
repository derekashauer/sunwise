#!/usr/bin/env node
/**
 * Generates the PWA icons in public/icons/ from the leaf glyph in
 * public/favicon.svg. Needs Playwright with Chromium installed:
 *
 *   node scripts/generate-icons.cjs
 *
 * The PNGs are committed, so this only needs to run when the logo changes.
 */
const fs = require('fs')
const path = require('path')
const { chromium } = require('playwright')

const OUT_DIR = path.join(__dirname, '..', 'public', 'icons')
const GREEN = '#22c55e'

// The leaf and stand from favicon.svg, drawn on a 100x100 grid.
const GLYPH = `
  <path d="M50 75V35M50 35c-8-12-22-16-32-10 14 2 22 14 28 25M50 35c8-12 22-16 32-10-14 2-22 14-28 25" stroke="COLOR" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
  <path d="M35 85h30" stroke="COLOR" stroke-width="6" stroke-linecap="round"/>
`

/**
 * Build an SVG of the icon.
 * background: fill color or null for transparent
 * rounded: corner radius on the 100 grid (0 for a full square)
 * scale: how much of the canvas the glyph uses (1 = same as favicon)
 * color: glyph color
 */
function svg({ background, rounded = 0, scale = 1, color = 'white' }) {
  const offset = (100 - 100 * scale) / 2
  const bg = background
    ? `<rect width="100" height="100" rx="${rounded}" fill="${background}"/>`
    : ''
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100%" height="100%">
    ${bg}
    <g transform="translate(${offset} ${offset}) scale(${scale})">${GLYPH.replace(/COLOR/g, color)}</g>
  </svg>`
}

const ICONS = [
  // Regular icons keep the favicon's rounded square.
  { file: 'icon-192.png', size: 192, art: svg({ background: GREEN, rounded: 20 }), transparent: true },
  { file: 'icon-512.png', size: 512, art: svg({ background: GREEN, rounded: 20 }), transparent: true },
  // Maskable icons must fill the square and keep the logo inside the center
  // 80% safe zone, because the OS crops them to its own shape.
  { file: 'maskable-512.png', size: 512, art: svg({ background: GREEN, scale: 0.7 }) },
  // iOS rounds the corners itself, so a full square looks right there.
  { file: 'apple-touch-icon.png', size: 180, art: svg({ background: GREEN, scale: 0.85 }) },
  // Android draws the notification badge as a one-color mask.
  { file: 'badge-72.png', size: 72, art: svg({ background: null, scale: 1.05, color: 'white' }), transparent: true }
]

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true })
  const browser = await chromium.launch()
  const page = await browser.newPage({ deviceScaleFactor: 1 })

  for (const icon of ICONS) {
    await page.setViewportSize({ width: icon.size, height: icon.size })
    await page.setContent(`<!doctype html><html><head><style>
      html, body { margin: 0; padding: 0; background: transparent; }
      div { width: ${icon.size}px; height: ${icon.size}px; }
    </style></head><body><div>${icon.art}</div></body></html>`)
    await page.locator('div').screenshot({
      path: path.join(OUT_DIR, icon.file),
      omitBackground: !!icon.transparent
    })
    console.log(`Wrote ${icon.file} (${icon.size}x${icon.size})`)
  }

  await browser.close()
}

main().catch(err => {
  console.error(err)
  process.exit(1)
})
