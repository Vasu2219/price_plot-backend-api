# Ollama Chat Setup Guide

## Prerequisites

1. **Install Ollama**
   - Download from https://ollama.ai
   - Install and run it on your machine

2. **Pull an Ollama Model**
   
   Run one of these commands in terminal:
   ```bash
   # Recommended options:
   ollama pull llama2           # 4GB - Fast, good quality
   ollama pull mistral          # 5GB - Better reasoning
   ollama pull neural-chat      # 4GB - Optimized for chat
   ollama pull phi              # 2.7GB - Smallest, fastest
   ```

3. **Start Ollama**
   ```bash
   ollama serve
   ```
   This runs Ollama on `http://localhost:11434`

## Configuration

### Backend Configuration

Set environment variables in your backend `.env` file:

```env
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=llama2
```

Or the API will use defaults:
- `OLLAMA_BASE_URL`: `http://localhost:11434`
- `OLLAMA_MODEL`: `llama2`

### Database Setup

Run this SQL to create chat history table:

```sql
CREATE TABLE IF NOT EXISTS chat_history (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  user_message LONGTEXT NOT NULL,
  assistant_message LONGTEXT NOT NULL,
  model_used VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX (user_id, created_at)
);
```

## Available Ollama Models

| Model | Size | Speed | Quality | Best For |
|-------|------|-------|---------|----------|
| `llama2` | 4GB | ⚡⚡⚡ | ⭐⭐⭐ | General purpose |
| `mistral` | 5GB | ⚡⚡ | ⭐⭐⭐⭐ | Better reasoning |
| `neural-chat` | 4GB | ⚡⚡⚡ | ⭐⭐⭐ | Chat optimized |
| `phi` | 2.7GB | ⚡⚡⚡⚡ | ⭐⭐ | Fast & light |
| `dolphin-mixtral` | 26GB | ⚡ | ⭐⭐⭐⭐⭐ | Highest quality |

## Testing

### Via cURL
```bash
curl http://localhost:11434/api/chat \
  -d '{
    "model": "llama2",
    "messages": [
      {"role": "user", "content": "Hello!"}
    ],
    "stream": false
  }'
```

### Via Web App
1. Login to PricePlot web app
2. Click the **📊 AI Assistant** button (floating)
3. Type a message and send

### Check Ollama Status
```bash
curl http://localhost:11434/api/tags
```

## Troubleshooting

**Error: "Ollama service unavailable"**
- Make sure Ollama is running: `ollama serve`
- Check the base URL is correct in config

**Error: "Model not found"**
- Pull the model first: `ollama pull llama2`
- Check model name matches exactly

**Slow responses**
- Try a smaller model: `phi` or `neural-chat`
- Check system RAM is sufficient

## API Endpoint

**POST** `/api/chat`

Request:
```json
{
  "message": "What is the best price?",
  "conversationHistory": [
    {"role": "user", "content": "Hello"},
    {"role": "assistant", "content": "Hi there!"}
  ]
}
```

Response:
```json
{
  "success": true,
  "message": "Here are the best prices...",
  "model": "llama2"
}
```

## Performance Tips

1. **Use smaller models** for faster responses
2. **Run Ollama on same machine** as backend for low latency
3. **Increase GPU VRAM** if available (CUDA support)
4. **Batch messages** to reduce API calls

## More Info

- Ollama GitHub: https://github.com/ollama/ollama
- Ollama Models: https://ollama.ai/library
- Ollama API Docs: https://github.com/ollama/ollama/blob/main/docs/api.md
