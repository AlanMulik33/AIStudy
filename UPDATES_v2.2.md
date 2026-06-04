# 🚀 Latest Updates - June 1, 2026

## 🆕 **Separate Launch Modes**

Sekarang ada **2 launcher terpisah** sesuai kebutuhan:

### **Option 1: Chat Mode** (Rekomendasi untuk belajar)
```bash
start-chat.bat
```
- 💬 Chat with conversation history
- 📋 Continue previous conversations
- 🔄 Study Mode / ChitChat mode selector
- 🎨 Modern ChatGPT-like interface
- **Port**: 8000
- **URL**: http://localhost:8000/chat.html

### **Option 2: Material Study Tools** (Untuk analisis materi)
```bash
start-materi.bat
```
- 📄 Upload materi (PDF, TXT, PPTX, Gambar)
- 📝 Ringkasan - Ringkas poin penting
- ❓ Soal Latihan - Generate 10 soal pilihan ganda
- 🎴 Flashcard - Buat 15 kartu hafalan
- **Port**: 8001
- **URL**: http://localhost:8001

---

## ✨ **UI Improvements**

### **Better File Attachment Area**
- ✅ Clear visual file preview
- ✅ Show file name + size (e.g., "document.pdf (2.5MB)")
- ✅ Maximum 20MB indicator
- ✅ 📎 Lampir button with better styling
- ✅ ✕ button to remove attached file

**Before**:
```
Input field
📎 button (minimal)
```

**After**:
```
Input field: "Ketik pertanyaan... atau lampirkan file"
📎 Lampir | Max 20MB
────────────────────────────────────
📎 document.pdf (2.5MB)    ✕
────────────────────────────────────
```

---

## 🔄 **Smart Retry System** (NEW!)

### **Problem Solved**: "Server AI sedang sibuk" errors
- ❌ **Before**: Gagal langsung saat "high demand"
- ✅ **After**: Auto retry 3x dengan intelligent delay

### **How It Works**:

```
Request 1 ──→ Rate Limit? → Wait 1s → Retry
Request 2 ──→ Rate Limit? → Wait 2s → Retry  
Request 3 ──→ Rate Limit? → Wait 4s → Retry
Request 4 ──→ Success! ✅ or Final Error ❌
```

### **Features**:
- ✅ 3 automatic retry attempts (was 2)
- ✅ Exponential backoff: 1s → 2s → 4s → 8s → 10s max
- ✅ Detects multiple rate limit errors:
  - "high demand"
  - "RESOURCE_EXHAUSTED"
  - "too many requests"
  - "RATE_LIMIT_EXCEEDED"
- ✅ Longer timeouts:
  - Request timeout: 120s (was 90s)
  - Connection timeout: 15s (was 10s)

### **Better Error Messages**:
- ⏳ "Server AI sedang sibuk setelah 3 percobaan"
- 💡 "Tips: Coba dengan pertanyaan yang lebih singkat atau file yang lebih kecil"

---

## 📦 **Text Chunking** (NEW!)

### **Problem**: Large PDFs/materials cause rate limiting
- Before: Send entire 100-page PDF at once → Often timeout
- After: Smart chunking for large content

### **How It Works**:

```
Large Material (50KB+)
     ↓
Split by paragraphs
     ↓
Keep sections that fit (max 30KB)
     ↓
Add note: "[... materi panjang dipotong untuk efisiensi ...]"
     ↓
Send optimized prompt
```

### **Benefits**:
- ✅ Faster processing
- ✅ Less likely to hit rate limits
- ✅ Preserves paragraph structure
- ✅ Handles 100+ page PDFs better

---

## 📊 **Comparison Table**

| Feature | Before | After |
|---------|--------|-------|
| Launchers | 1 (start-server.bat) | 2 (start-chat.bat, start-materi.bat) |
| Attachment UI | Basic | Enhanced with preview |
| Retry attempts | 2 | 3 |
| Max timeout | 90s | 120s |
| Rate limit handling | Fail immediately | Auto retry with backoff |
| Text chunking | No | Yes (>30KB) |
| Error messages | Technical | User-friendly |
| Rate limit detection | 2 types | 4 types |

---

## 🎯 **Usage Guide**

### **For Chat (Recommended)**
```
1. Run: start-chat.bat
2. Type question
3. Chat history saved automatically
4. Click old chats to continue
5. Switch between Study Mode / ChitChat
6. Optional: Attach PDF/image for context
```

### **For Material Analysis**
```
1. Run: start-materi.bat
2. Upload materi (PDF/TXT/PPTX/Image)
3. Choose tool:
   - Ringkasan (summary)
   - Soal Latihan (quiz)
   - Flashcard (study cards)
4. Click "Proses Materi" button
```

---

## 🔧 **Technical Details**

### **Retry Logic Flow**:
```php
for retry 0 to 3:
    try API request
    if rate_limited:
        sleep(exponential_backoff)
        continue to next retry
    elif model_not_found:
        break (skip to next model)
    elif success:
        return response
    else:
        return error
```

### **Chunking Logic**:
```php
if strlen(prompt) > 30KB:
    chunks = split_by_paragraphs()
    keep adding until 30KB
    add truncation note
```

---

## 📝 **Git Commits**

```
f3e0517 ✅ Improve UX with separate launchers, smart retry, better attachments
96117c0 ✅ Complete UI redesign - Modern ChatGPT-like interface
6e7b9ba ✅ Add retry mechanism with exponential backoff
fce2898 ✅ Use only gemini-2.5-flash model
831acdf ✅ Update Gemini models to valid v1beta
```

---

## 💡 **Pro Tips**

1. **For chat**: Use `start-chat.bat` (modern interface, history saved)
2. **For materials**: Use `start-materi.bat` (tools: summary, quiz, flashcard)
3. **Large files**: Chat mode auto-chunks large PDFs (>30KB)
4. **Rate limited**: Just wait, system will retry automatically up to 3 times
5. **Both running**: Open another terminal, run the other launcher on different port

---

**Version**: 2.2.0  
**Updated**: June 1, 2026  
**Status**: ✅ Production Ready
