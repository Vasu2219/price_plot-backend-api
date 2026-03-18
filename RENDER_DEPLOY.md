# Render One-Go Deploy

## 1) Push code
This folder already contains `render.yaml` and `Dockerfile`.

## 2) Create Web Service from Blueprint
In Render dashboard:
1. **New +** -> **Blueprint**
2. Select your GitHub repo and branch
3. Render will detect `render.yaml` and create `price-plot-backend-api`

## 3) Set required environment variables
Set these in Render service settings:
- `MONGODB_URI` = your MongoDB Atlas URI
- `MONGODB_DB` = `price_plot` (or your preferred DB name)
- `ALLOWED_ORIGINS` = comma-separated frontend URLs
  - Example: `http://localhost:8080,https://your-frontend-domain.com`

Optional:
- `OLLAMA_MODEL` = `qwen2.5:3b`
- `OLLAMA_BASE_URL` = Ollama endpoint URL
- `API_DEBUG_ERRORS` = `false` (set to `true` only temporarily while debugging backend errors)

## 4) Deploy
Click **Manual Deploy** -> **Deploy latest commit**.

## 5) Verify
- Health: `https://<your-render-service>.onrender.com/api/health.php`
- API base URL for frontend:
  - `https://<your-render-service>.onrender.com/api`

## Ollama note
If Ollama runs only on your laptop, Render cannot directly access `localhost:11434`.
Use either:
- frontend `ollama-direct` mode (already implemented in frontend), or
- expose local Ollama via secure tunnel and set `OLLAMA_BASE_URL` to that public URL.
