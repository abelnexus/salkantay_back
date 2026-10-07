# Salkantay — Backend (API)

API REST del sistema Salkantay: autenticación con tokens, gestión de usuarios y roles/permisos.

| Repositorio | Stack | URL local |
| --- | --- | --- |
| **salkantay_back** (este) | Laravel 13 · PHP 8.4-FPM · Nginx · MySQL 8.4 | http://localhost:8081/api |
| [salkantay_front](https://github.com/abelnexus/salkantay_front) | Angular 22 · Node 22 | http://localhost:4200 |

El sistema completo necesita **los dos repositorios corriendo a la vez**.

---

## Requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (incluye Docker Compose)
- Git

No hace falta instalar PHP, Composer, Node ni MySQL: todo corre dentro de contenedores.

Puertos que deben estar libres: **8081** (API), **3307** (MySQL), **4200** (front).

---

## Cómo levantar el sistema completo

### 1. Clonar ambos repositorios

```bash
git clone https://github.com/abelnexus/salkantay_back.git
git clone https://github.com/abelnexus/salkantay_front.git
```

### 2. Backend

```bash
cd salkantay_back
cp .env.example .env
docker compose up -d --build

# Solo la primera vez:
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

> Si `migrate` falla con *Connection refused*, MySQL aún está arrancando: espera unos segundos y vuelve a ejecutarlo.

Comprobar que responde: http://localhost:8081/api/health

### 3. Frontend

```bash
cd ../salkantay_front
docker compose up -d --build
```

La primera vez tarda un poco (instala dependencias y compila). Ver el avance con `docker compose logs -f front`.

### 4. Entrar

Abrir **http://localhost:4200** e iniciar sesión con el usuario creado por el seeder:

| Email | Contraseña | Rol |
| --- | --- | --- |
| `test@example.com` | `password` | Administrador (acceso total) |

---

## Endpoints principales

Todos bajo `/api`. Salvo `health` y `login`, requieren `Authorization: Bearer <token>` (Laravel Sanctum).

| Método | Ruta | Descripción |
| --- | --- | --- |
| GET | `/health` | Verificación de conexión |
| POST | `/login` | Inicia sesión y devuelve el token (máx. 5 intentos/min) |
| GET | `/me` | Usuario autenticado y sus permisos |
| POST | `/logout` | Revoca el token |
| GET/POST/PUT/DELETE | `/users` | CRUD de usuarios |
| POST | `/users/bulk-delete` · `/users/bulk-verify` · `/users/export` | Acciones masivas |
| GET/POST/PUT/DELETE | `/roles` | CRUD de roles |
| GET | `/permissions` | Catálogo de permisos |
| PUT | `/roles/permissions` | Guarda la matriz rol × permiso |

## Tests

```bash
docker compose exec app php artisan test
```

## Base de datos

Para conectarse desde un cliente externo (TablePlus, DBeaver…):

| Host | Puerto | Usuario | Contraseña | Base de datos |
| --- | --- | --- | --- | --- |
| `127.0.0.1` | `3307` | `salkantay` | `secret` | `salkantay` |

Dentro de Docker, Laravel se conecta con `DB_HOST=db` y `DB_PORT=3306` (ya configurado en `.env.example`).

## Comandos útiles

```bash
docker compose ps                 # qué está corriendo
docker compose logs -f app        # logs en vivo (app | nginx | db)
docker compose down               # apagar (agrega -v para borrar también la BD)
docker compose exec app php artisan migrate:fresh --seed   # reiniciar la BD con datos de prueba
```
