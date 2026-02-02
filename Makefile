TAG := $(shell test -d .git && git tag --points-at HEAD)
PWD := $(shell pwd)

all: v2 v3 v4

v2: .v2
v3: .v3
v4: .v4

.v%:
	$(eval VER := $(subst .v,,$@))

	/bin/bash $(PWD)/build.sh $(PWD) ${VER} ${TAG}

clean:
	rm -rf '$(PWD)/build'

.PHONY: v2 v3 v4 clean
