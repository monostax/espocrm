# Cache directory
COMPONENT_NAME := center
SUBCOMPONENT_NAME := espocrm
CACHE_COMPONENT := $(MONOSTAX_GLOBAL_CACHE_PATH_ROOT)/$(COMPONENT_NAME)
CACHE_SUBCOMPONENT := $(CACHE_COMPONENT)/$(SUBCOMPONENT_NAME)
WATCH_FILES := package.json package-lock.json composer.json composer.lock $(shell find application -name "*.php" 2>/dev/null) $(shell find html -name "*.html" 2>/dev/null)

.PHONY: build

vendor/composer/installed.json: composer.json composer.lock
	@echo "Installing dependencies..."
	nix develop ../ --command composer install
	@touch $@

# Install dependencies
node_modules/.WAS_INSTALLED: package.json package-lock.json
	@echo "Installing dependencies..."
	nix develop ../ --command npm install
	@touch $@

$(CACHE_SUBCOMPONENT)/build/index.php: $(WATCH_FILES) node_modules/.WAS_INSTALLED vendor/composer/installed.json
	@echo "Building component.center espocrm..."
	nix develop ../ --command ./node_modules/.bin/grunt --force
	@mkdir -p $(CACHE_SUBCOMPONENT)/build/
	@cp -r build/EspoCRM-*/* $(CACHE_SUBCOMPONENT)/build/
	@touch $@

# Build the project
build: node_modules/.WAS_INSTALLED $(CACHE_SUBCOMPONENT)/build/index.php
