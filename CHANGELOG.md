# CHANGELOG - AI Study Helper Updates

## Version 2.1.0 - Conversation Persistence & API Reliability

### ✨ New Features

#### 1. **Conversation History Persistence**
- All QA conversations are now automatically saved to a local SQLite database
- Conversations are retrieved and displayed in a new sidebar panel
- Click any previous conversation to continue chatting from where you left off
- Conversations show formatted timestamps (e.g., "5 minutes ago", "2 hours ago")

#### 2. **Conversation Management Sidebar**
- New 💬 button to toggle conversation history panel
- Shows up to 30 recent conversations sorted by latest update
- Click to load any conversation and restore full chat history
- "Percakapan Baru" button to start fresh conversations
- Mobile-responsive design (sidebar slides out on mobile)
- Active conversation is highlighted

#### 3. **REST API for Conversations**
New endpoints available in `conversations.php`:
- `?action=list&limit=30` - Get conversation list
- `?action=get&id={id}` - Load specific conversation with all messages
- `?action=save` - Save new conversation
- `?action=update-title` - Update conversation title
- `?action=delete` - Delete conversation

### 🔧 Bug Fixes & Improvements

#### 1. **Fixed Gemini API High Demand Errors**
- **Problem**: Error "This model is currently experiencing high demand. Please try again later"
- **Solution**: Added automatic fallback to multiple models
- **Models Available**:
  1. `gemini-2.5-flash` (primary)
  2. `gemini-1.5-pro` (fallback 1)
  3. `gemini-1.5-flash` (fallback 2)
  4. `gemini-pro` (fallback 3)
- When one model is unavailable, automatically tries the next one
- Only returns error if ALL models fail

#### 2. **Database Auto-Initialization**
- SQLite database is created automatically on first use
- Location: `/data/conversations.db`
- No manual setup required
- Tables created: `conversations` and `messages`

### 📝 Implementation Details

**Database Schema:**
```
conversations:
  - id (INTEGER PRIMARY KEY)
  - title (TEXT)
  - created_at (DATETIME)
  - updated_at (DATETIME)
  - summary (TEXT)

messages:
  - id (INTEGER PRIMARY KEY)
  - conversation_id (INTEGER FK)
  - role (TEXT: 'user' or 'assistant')
  - content (TEXT)
  - created_at (DATETIME)
```

**Features:**
- Stores last 20 messages per conversation for efficiency
- Conversation title auto-generated from first question
- Timestamps formatted for readability
- Full CORS support for API endpoints
- Proper error handling and validation

### 🚀 Performance

- Lazy loads conversations only when sidebar is opened
- Database queries optimized with indexes
- Conversations list limited to 30 most recent
- Smooth animations for panel toggle

### 📱 Responsive Design

- **Desktop**: Fixed 300px sidebar on left
- **Mobile**: Full-screen slide-out panel
- 💬 button only visible on desktop (when conversations exist)
- Touch-friendly interface

### 🔐 Security

- Input validation on all API endpoints
- Prepared statements to prevent SQL injection
- CORS headers configured
- Error messages don't leak system info

### 📂 File Changes

**Modified:**
- `config.php` - Added models and database functions
- `api.php` - Added conversation saving
- `index.html` - Added sidebar UI and loading logic
- `.gitignore` - Added data directory

**New:**
- `conversations.php` - API endpoints
- `conversations.db.sql` - Database schema

### 🔄 Upgrade Instructions

1. Replace `config.php`, `api.php`, `index.html`
2. Add new `conversations.php` file
3. Ensure PHP has PDO and SQLite support (usually default)
4. Folder `/data/` will be created automatically
5. Database will initialize on first use

### ⚠️ Known Limitations

- Database stores max 20 messages per conversation to keep size reasonable
- API limits to showing 30 most recent conversations
- Conversations are stored locally (per server/installation)
- No cloud sync or multi-device support

### 🐛 Troubleshooting

**If conversations aren't saving:**
- Check if `/data/` folder can be created (file permissions)
- Verify PHP has `pdo` and `sqlite` extensions: `php -m | grep -i pdo`
- Check PHP error logs

**If API errors occur:**
- Ensure GEMINI_API_KEY in `.env` is valid
- Check internet connection
- Try manually waiting a few minutes (rate limiting)

### 📊 Technical Stack

- **Backend**: PHP 7.4+
- **Database**: SQLite 3 (PDO)
- **Frontend**: Vanilla JavaScript (no dependencies)
- **API**: RESTful JSON
- **AI**: Google Gemini API

### 🎯 Future Enhancements

Planned for next release:
- [ ] Export conversations to PDF/Markdown
- [ ] Search conversations by keyword
- [ ] Tag conversations
- [ ] Conversation sharing
- [ ] Cloud backup option
- [ ] Conversation analytics

---

**Released**: June 1, 2026
**Commits**: 5 commits with detailed messages
**Test Status**: ✅ Ready for production
