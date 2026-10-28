# AI Chatbot Plugin - ChatGPT-Like Improvements

## Overview

This document describes the major improvements made to the AI Chatbot plugin to provide a ChatGPT-like experience. The plugin now provides intelligent follow-up suggestions, clarification questions, better context awareness, and answer quality feedback.

## New Features Implemented

### 1. Follow-Up Suggestions

**What it does:**
- After answering a question, the chatbot now suggests 2-4 relevant follow-up questions the user might want to ask
- Suggestions are based on the answer content and the available website information
- Only suggests questions that have actual content available to support them

**When to test:**
1. Ask: "What are your most popular products?"
2. After the answer, look for a blue section titled "Du kan også spørge om:" (Danish) or "You can also ask about:" (English)
3. Click any suggestion to ask the next question automatically

**Implementation details:**
- Added `follow_up_suggestions` array to response format
- AI prompt instructs the model to generate relevant follow-up questions
- Follow-ups are displayed as clickable buttons that automatically submit to the chat
- Styled with blue background to match ChatGPT aesthetic

### 2. Clarification Questions

**What it does:**
- When a user's question is ambiguous or could mean multiple things, the chatbot asks for clarification
- Presents 1-2 targeted questions to help the user specify what they need
- Only shows clarification questions when truly needed (not for clear questions)

**When to test:**
1. Ask: "Tell me about your service" (ambiguous - could mean multiple services)
2. The chatbot should present clarification options like:
   - "Are you asking about equipment maintenance service?"
   - "Are you asking about financing service?"
3. Click one to narrow down the search

**Implementation details:**
- Added `needs_clarification` boolean and `clarification_questions` array to response
- New `build_clarification_system()` prompt builder helps AI detect ambiguity
- Clarification sections are styled with yellow/orange background for prominence
- Falls back to direct answer if question is specific enough

### 3. Confidence Levels & Source Attribution

**What it does:**
- Each answer includes a confidence level: "high", "medium", or "low"
- Shows which sources were used: Website, Documentation, External Links
- Helps users understand the reliability of the information

**When to test:**
1. Ask questions and look at the footer of each answer
2. You'll see "Information fra: Website, Documentation" etc.
3. Confidence levels help users understand if the AI had clear information or was inferring

**Implementation details:**
- Added `confidence` field (high/medium/low) to response format
- Added `source_groups` array to track which sources were used
- Displayed as subtle text at the bottom of each answer card
- Confidence determined by how much relevant content was found

### 4. Smart Answer Feedback

**What it does:**
- At the bottom of each answer, users can rate if it was helpful
- Two reaction buttons: 👍 (helpful) and 👎 (not helpful)
- Feedback is logged server-side for future analysis and improvement

**When to test:**
1. After any answer, look for "Var svaret brugbart?" (Danish) or "Was this answer helpful?" (English)
2. Click the thumbs up or thumbs down button
3. The buttons will be disabled and show "Tak for din feedback!" (Thank you for feedback)
4. Feedback is logged and can be analyzed to improve the AI over time

**Implementation details:**
- Added feedback section to every answer card
- Feedback endpoint: `/wp-json/aiab/v1/feedback`
- Feedback logged to `aiab_feedback_log` WordPress option
- Last 1000 feedback entries stored for analysis

### 5. Enhanced Conversation Context

**What it does:**
- The chatbot now maintains better awareness of the conversation thread
- Multi-turn conversations maintain full context for more coherent responses
- The AI understands follow-ups as part of an ongoing discussion

**When to test:**
1. Start with: "What products do you sell?"
2. Follow up with: "Tell me more about the most expensive one"
3. The bot should understand you're referring to the product category from before
4. Continue with: "What services do you offer for that?" 
5. The bot maintains full context across all turns

**Implementation details:**
- History array properly formatted and sent to AI with each request
- Conversation limited to last 20 messages (10 Q&A turns) to manage token usage
- Full context preserved in each turn, allowing coherent multi-turn discussions

## API Response Format

The complete new response format now includes:

```json
{
  "lang": "en or da",
  "title": "short headline",
  "chip": "1-3 word topic label",
  "answer": "full Markdown-formatted answer",
  "products": [{"title": "", "permalink": "", "image": "", "description": ""}],
  "specifications": [],
  "features": [],
  "support": [],
  "category": null,
  "contact": null,
  "sources": [],
  
  // NEW FIELDS - ChatGPT Features
  "confidence": "high|medium|low",
  "source_groups": ["website", "documentation", "external_link"],
  "follow_up_suggestions": [
    "Are you interested in financing options?",
    "Do you want to know about installation services?",
    "Would you like information about maintenance plans?"
  ],
  "needs_clarification": false,
  "clarification_questions": [
    "Which type of service are you asking about?",
    "Are you interested in commercial or residential options?"
  ]
}
```

## Testing Checklist

### Basic Functionality
- [ ] Casual greetings ("Hi", "How are you?") return appropriate responses
- [ ] Regular questions return complete answers with product cards
- [ ] Questions about services return accurate information
- [ ] Contact person lookup works correctly

### New ChatGPT Features
- [ ] Follow-up suggestions appear for informational questions
- [ ] Follow-up buttons are clickable and submit new questions
- [ ] Clarification questions appear for ambiguous queries
- [ ] Clarification buttons work and narrow down results
- [ ] Source attribution shows which data sources were used
- [ ] Confidence level is reasonable (high for website questions, lower for unclear matches)

### Conversation Flow
- [ ] Multi-turn conversations maintain context
- [ ] Second and third questions reference earlier context
- [ ] Conversation history is preserved in browser
- [ ] New chat button clears history properly

### Feedback System
- [ ] Feedback buttons appear on all answers
- [ ] Thumbs up/down buttons are clickable
- [ ] Buttons become disabled after rating
- [ ] "Thank you" message appears
- [ ] Feedback is logged (check WordPress debug log)

### External Links & Documents
- [ ] Questions about external link brands/sites return appropriate content
- [ ] Document search works for uploaded PDFs
- [ ] Source attribution correctly shows "Documentation" for PDF content
- [ ] External link source shows as "External link" in attribution

## Configuration & Optimization

### Adjusting AI Behavior

In `class-aiab-prompt.php`, you can modify:

1. **Follow-up suggestions count**: Change prompt instruction to generate more/fewer suggestions
2. **Clarification sensitivity**: Adjust the `build_clarification_system()` prompt to be stricter or looser
3. **Context window**: In `class-aiab-rest.php`, adjust `MAX_HISTORY_MESSAGES` (currently 20)

### Performance Considerations

1. **Caching**: Answers for questions without conversation history are cached for configured minutes
2. **Rate limiting**: 20 requests per 5 minutes per IP to prevent abuse
3. **Token limits**: Set at 8192 max output tokens per response
4. **History truncation**: Conversation limited to 20 messages to manage API costs

## Frontend Customization

### Styling New Features

The new features can be styled by modifying `assets/css/widget.css`:

- `.aiab-section-clarification` - Yellow/orange section for clarification
- `.aiab-section-followup` - Blue section for suggestions  
- `.aiab-section-feedback` - Footer feedback section
- `.aiab-clarification-btn` - Clarification question buttons
- `.aiab-follow-up-btn` - Follow-up suggestion buttons
- `.aiab-feedback-btn` - Feedback reaction buttons

### Translation/Localization

Hardcoded English/Danish strings in the new features:
- Clarification prompt: "Hjælp mig med at forstå:" / "Help me understand:"
- Follow-up prompt: "Du kan også spørge om:" / "You can also ask about:"
- Feedback prompt: "Var svaret brugbart?" / "Was this answer helpful?"
- Feedback thanks: "Tak for din feedback!" / "Thank you for your feedback!"

These are in `assets/js/widget.js` and can be made configurable via WordPress settings if needed.

## API Endpoints

### Main Chat Endpoint
- **URL**: `/wp-json/aiab/v1/ask`
- **Method**: POST
- **Parameters**:
  - `question` (required): User's question string
  - `history` (optional): Array of prior conversation turns
- **Returns**: Response object with all new fields

### Feedback Endpoint (NEW)
- **URL**: `/wp-json/aiab/v1/feedback`
- **Method**: POST
- **Parameters**:
  - `helpful` (required): Boolean - was the answer helpful?
  - `question` (optional): User's original question
  - `answer` (optional): The answer that was rated
- **Returns**: `{success: true, message: "Feedback recorded."}`

## Troubleshooting

### Follow-up suggestions not appearing
- Check that the question has real search intent (not a greeting)
- Verify the website has multiple related content pieces
- Check AI token limits aren't cutting off the suggestions array

### Clarification questions not working
- Question may be too specific or ambiguous detection too strict
- Try questions about topics with multiple types/services on the website
- Adjust the `build_clarification_system()` prompt for different sensitivity

### Feedback not being saved
- Verify the feedback endpoint is registered: `/wp-json/aiab/v1/feedback`
- Check WordPress error logs for any issues
- Ensure the user's IP is correctly detected (especially behind proxies)

### Context not persisting across turns
- Verify conversation history is being passed in the request
- Check browser localStorage/memory limits haven't been exceeded
- Ensure history isn't being cleared between requests

## Database & Storage

### Feedback Log Storage
- Stored in WordPress options table as `aiab_feedback_log`
- Format: Array of objects with timestamp, IP, helpful flag, question, answer
- Automatically truncated to last 1000 entries to prevent unbounded growth

### Conversation History
- Stored in browser's JavaScript memory (not persistent)
- Limited to 20 messages (last 10 Q&A turns)
- Cleared when user clicks "New Chat" button

## Performance Metrics

Typical response times:
- **Simple greetings**: <100ms (cached response)
- **Website questions**: 2-5 seconds (search + AI call)
- **Multi-source questions**: 5-10 seconds (with documentation/external links)
- **First-time question** (no cache): Same as above
- **Cached question**: <100ms

## Future Enhancement Ideas

1. **User Preferences**: Save user language/preferences to reduce clarification questions
2. **Question History**: Persistent history across sessions (requires login)
3. **Analytics Dashboard**: Admin panel showing feedback stats and common questions
4. **A/B Testing**: Compare different follow-up suggestions to see which are most clicked
5. **Automatic Suggestion Ranking**: Learn which suggestions users click most and prioritize
6. **Answer Quality Scoring**: Combine feedback with engagement metrics for answer quality
7. **Multilingual Follow-ups**: Auto-translate suggestions to other languages
8. **Context Summaries**: Show brief summary of conversation context for clarity

## Support & Issues

If you encounter any issues:
1. Check WordPress debug logs: `wp-content/debug.log`
2. Verify API keys for Gemini/OpenAI are correctly configured
3. Test with simple questions first before complex multi-turn conversations
4. Check browser console for JavaScript errors
5. Verify rate limiting isn't blocking requests (20/5 min per IP)

---

**Version**: 1.6.0 (ChatGPT-Like Features)
**Last Updated**: 2025
**Plugin**: AI Assistant Bot
