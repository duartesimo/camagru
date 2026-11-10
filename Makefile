COMPOSE := docker compose

help:
	@echo "help     List available commands"
	@echo "up       Build and start the application"
	@echo "down     Stop containers and keep database data"
	@echo "restart  Restart the application"
	@echo "logs     Follow container logs"
	@echo "ps       Display container status"
	@echo "build    Rebuild Docker images"

up:
	$(COMPOSE) up --build -d

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps

build:
	$(COMPOSE) build

.PHONY: help up down restart logs ps build
