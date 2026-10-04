# Build dependency compatibility

The npm overrides in `package.json` keep the EspoCRM Grunt build on patched
dependencies without upgrading the application framework:

- `glob` 13 retains the synchronous API used by Grunt and Babel and the
  promise-based API used by archiver and Jasmine.
- Babel CLI 7 uses Chokidar 4 to watch directories/files. Chokidar 4 removes
  the vulnerable `braces` dependency; glob-based watch paths are not supported.
- `findup-sync` is a small local ancestor-lookup adapter using maintained
  `minimatch`. Both Grunt and liftup otherwise depend on `micromatch` and
  `braces`, which has no published fix for GHSA-vfj7-8cjw-p6xm.
- Selectize's sifter uses CSV parsing for its development utilities. The
  override upgrades that parser to the patched 7.x API.
- `get-uri` uses the patched `basic-ftp` 6.x client through the same client API.

`.npmrc` enables `install-links` so the local adapter is installed as a regular
package, rather than a relative symlink inside transitive dependency folders.
Run `node --test js/build-dependencies/findup-sync/*.test.cjs` to verify lookup.
