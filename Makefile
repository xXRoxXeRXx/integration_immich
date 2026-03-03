app_name    := integration_immich
build_dir   := $(CURDIR)/build
release_dir := $(build_dir)/release
source_dir  := $(build_dir)/source/$(app_name)

# Files/dirs to include in the release ZIP
appfiles := appinfo \
            composer \
            css \
            img \
            js \
            l10n \
            lib \
            templates \
            CHANGELOG.md \
            COPYING \
            README.md

.PHONY: all clean build release

all: build

## Install JS dependencies
npm-install:
	npm ci

## Build production JS bundles
build: npm-install
	npm run build

## Build JS + create release ZIP
# Note: no vendor/ or composer install needed - composer/autoload.php is a
# hand-written PSR-4 autoloader that ships directly in the release ZIP.
release: build
	@echo "--- Assembling release package ---"
	rm -rf "$(source_dir)"
	mkdir -p "$(source_dir)"
	cp -r $(appfiles) "$(source_dir)/"
	mkdir -p "$(release_dir)"
	cd "$(build_dir)/source" && find "$(app_name)/js" -name "*.map" -delete
	cd "$(build_dir)/source" && tar -czf "$(release_dir)/$(app_name).tar.gz" "$(app_name)"
	@echo ""
	@echo "✅  Release tar.gz: $(release_dir)/$(app_name).tar.gz"

## Remove build artifacts
clean:
	rm -rf "$(build_dir)"
	rm -rf node_modules
