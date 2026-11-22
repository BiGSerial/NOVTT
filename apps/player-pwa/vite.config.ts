import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { defineConfig, type Plugin, type ResolvedConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

function listFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap(entry =>
    entry.isDirectory() ? listFiles(join(dir, entry.name)) : [join(dir, entry.name)])
}

// Stamps public/sw.js with a hash of the built output so each release changes the worker's
// bytes (forcing the browser to reinstall it) and uses a fresh, bounded cache.
function serviceWorkerBuildId(): Plugin {
  let config: ResolvedConfig
  return {
    name: 'novtt-sw-build-id',
    apply: 'build',
    configResolved(resolved) { config = resolved },
    closeBundle() {
      const outDir = join(config.root, config.build.outDir)
      const swPath = join(outDir, 'sw.js')
      const hash = createHash('sha256')
      for (const file of listFiles(outDir).filter(file => file !== swPath).sort()) {
        hash.update(file.slice(outDir.length)).update(readFileSync(file))
      }
      const buildId = hash.digest('hex').slice(0, 12)
      const source = readFileSync(swPath, 'utf8')
      if (!source.includes('__BUILD_ID__')) throw new Error('sw.js is missing the __BUILD_ID__ placeholder')
      writeFileSync(swPath, source.replaceAll('__BUILD_ID__', buildId))
    },
  }
}

// Development only: same-origin proxy to the API so the first-party session cookie works
// (ADR 0009). demo.html is a dev-only entry and is not part of the production build.
const apiProxy = process.env.NOVTT_API_PROXY ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [vue(), serviceWorkerBuildId()],
  server: {
    // The demo page imports the framework-free master review panel.
    fs: { allow: ['.', '../master-web/resources/js'] },
    proxy: {
      '/api': { target: apiProxy },
      '/sanctum': { target: apiProxy },
    },
  },
})
