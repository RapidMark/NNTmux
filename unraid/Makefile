USERNAME=rapidmark
IMAGE=nntmux
TAG=dev
APP_IMAGE=nntmux-app:build

app:
	docker build -t $(APP_IMAGE) ..

build: app
	docker build -t $(USERNAME)/$(IMAGE):$(TAG) --build-arg APP_IMAGE=$(APP_IMAGE) .

push:
	docker push $(USERNAME)/$(IMAGE):$(TAG)

lint:
	docker run --rm -i -v "$(CURDIR)/../.github/linters/.hadolint.yaml:/.hadolint.yaml:ro" hadolint/hadolint hadolint --config /.hadolint.yaml - < Dockerfile
