import { copyFileSync, existsSync, mkdirSync, readdirSync } from 'fs'
import { join, dirname } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = join(__dirname, '..')
const dest = join(root, 'public', 'assets', 'build', 'files')

const packages = [
    '@fontsource/inter',
    '@fontsource/playfair-display',
    '@fontsource/roboto',
    '@fontsource/open-sans',
    '@fontsource/montserrat',
]

const latinPattern = /-latin-\d+-normal\.woff2$/

if (!existsSync(dest)) {
    mkdirSync(dest, { recursive: true })
}

let copied = 0
for (const pkg of packages) {
    const srcDir = join(root, 'node_modules', pkg, 'files')
    if (!existsSync(srcDir)) {
        console.warn(`[copy-fonts] Source not found: ${srcDir}`)
        continue
    }
    const files = readdirSync(srcDir).filter((file) => latinPattern.test(file))
    for (const file of files) {
        copyFileSync(join(srcDir, file), join(dest, file))
        copied++
    }
    console.log(`[copy-fonts] Copied ${files.length} latin woff2 files from ${pkg}/files`)
}

console.log(`[copy-fonts] Done (${copied} files total)`)
