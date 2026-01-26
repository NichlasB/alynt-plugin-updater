import { build } from 'esbuild';
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const args = process.argv.slice(2);
const watch = args.includes('--watch');
const isProd = args.includes('--prod');

const outDir = resolve('assets', 'dist', 'admin');
const jsOut = resolve(outDir, 'index.js');
const cssOut = resolve(outDir, 'style.css');

const ensureDir = (path) => {
  mkdirSync(path, { recursive: true });
};

const copyCss = () => {
  ensureDir(dirname(cssOut));
  copyFileSync(resolve('assets', 'src', 'admin', 'style.css'), cssOut);
};

const buildJs = async () => {
  await build({
    entryPoints: [resolve('assets', 'src', 'admin', 'index.js')],
    bundle: true,
    outfile: jsOut,
    minify: isProd,
    sourcemap: false,
  });
};

const run = async () => {
  ensureDir(dirname(jsOut));
  await buildJs();
  copyCss();

  if (watch) {
    const { watch: watchBuild } = await build({
      entryPoints: [resolve('assets', 'src', 'admin', 'index.js')],
      bundle: true,
      outfile: jsOut,
      minify: isProd,
      sourcemap: false,
      watch: {
        onRebuild(error) {
          if (error) {
            console.error('Rebuild failed:', error);
          } else {
            copyCss();
            console.log('Rebuild complete.');
          }
        },
      },
    });
    if (watchBuild) {
      console.log('Watching for changes...');
    }
  }
};

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
