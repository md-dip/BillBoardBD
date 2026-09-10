import { useEffect, useRef, useState } from 'react';
import { Sparkles, X, SendHorizonal, Loader2 } from 'lucide-react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import './AssistantPanel.css';

// The BillboardBD Assistant - a floating chat panel a signed-in client or owner
// can ask anything in plain language. Genuinely shared: mounted once in App.jsx
// and rendered over whichever actor's pages happen to be on screen. Fully
// self-contained (own CSS import right here), so no page has to style it.
//
// The conversation lives only in this component's state: the API is stateless
// and the browser replays the turns it wants remembered, so closing the panel
// or reloading the page starts a fresh chat by design.

const ROLES_WITH_ASSISTANT = ['client', 'owner'];

// ------------------------------------------------------------------ markdown

// The model answers in light markdown - short paragraphs, "- " bullets, **bold**
// and the occasional table. Rendering that subset here keeps the SPA free of a
// markdown dependency, which matters in a project that deliberately ships no UI
// framework. Anything outside the subset degrades to plain text rather than
// showing raw syntax at the user.
function renderInline(text, keyPrefix) {
    return text.split(/(\*\*[^*]+\*\*)/g).filter(Boolean).map((part, i) =>
        part.startsWith('**') && part.endsWith('**')
            ? <strong key={`${keyPrefix}-${i}`}>{part.slice(2, -2)}</strong>
            : <span key={`${keyPrefix}-${i}`}>{part}</span>
    );
}

function splitRow(line) {
    return line.replace(/^\||\|$/g, '').split('|').map((cell) => cell.trim());
}

// A markdown separator row - |---|:--:|---| - which carries no content itself.
function isSeparatorRow(line) {
    return /^\|?[\s:|-]+\|[\s:|-]*$/.test(line) && line.includes('-');
}

function renderMarkdown(text) {
    const lines = text.split('\n');
    const blocks = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        if (line.trim() === '') {
            i += 1;
            continue;
        }

        // Table: a run of pipe rows, the second of which is the separator.
        if (line.trim().startsWith('|') && isSeparatorRow(lines[i + 1] ?? '')) {
            const header = splitRow(line.trim());
            const rows = [];
            i += 2;
            while (i < lines.length && lines[i].trim().startsWith('|')) {
                rows.push(splitRow(lines[i].trim()));
                i += 1;
            }
            blocks.push(
                <div className="assistant-table-scroll" key={`table-${i}`}>
                    <table className="assistant-table">
                        <thead>
                            <tr>{header.map((cell, c) => <th key={c}>{renderInline(cell, `th-${c}`)}</th>)}</tr>
                        </thead>
                        <tbody>
                            {rows.map((row, r) => (
                                <tr key={r}>{row.map((cell, c) => <td key={c}>{renderInline(cell, `td-${r}-${c}`)}</td>)}</tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            );
            continue;
        }

        // Bullet list: a run of "- " or "* " lines.
        if (/^\s*[-*]\s+/.test(line)) {
            const items = [];
            while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) {
                items.push(lines[i].replace(/^\s*[-*]\s+/, ''));
                i += 1;
            }
            blocks.push(
                <ul className="assistant-list" key={`ul-${i}`}>
                    {items.map((item, n) => <li key={n}>{renderInline(item, `li-${n}`)}</li>)}
                </ul>
            );
            continue;
        }

        // Everything else: consecutive lines joined into one paragraph.
        const paragraph = [];
        while (
            i < lines.length
            && lines[i].trim() !== ''
            && !lines[i].trim().startsWith('|')
            && !/^\s*[-*]\s+/.test(lines[i])
        ) {
            paragraph.push(lines[i]);
            i += 1;
        }
        blocks.push(
            <p className="assistant-paragraph" key={`p-${i}`}>
                {renderInline(paragraph.join(' '), `p-${i}`)}
            </p>
        );
    }

    return blocks;
}

// ----------------------------------------------------------------- component

export default function AssistantPanel() {
    const { user } = useAuth();
    const [enabled, setEnabled] = useState(false);
    const [suggestions, setSuggestions] = useState([]);
    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState([]);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const scrollRef = useRef(null);
    const inputRef = useRef(null);

    const canUseAssistant = Boolean(user) && ROLES_WITH_ASSISTANT.includes(user?.role);

    // Ask the API whether the feature is switched on at all. An install with no
    // ANTHROPIC_API_KEY set shows nothing, rather than a chat box that fails the
    // moment someone types in it.
    useEffect(() => {
        // No setState on this path: rendering is already gated on
        // canUseAssistant, and the request below settles `enabled` either way.
        if (!canUseAssistant) return;

        let cancelled = false;
        api.get('/assistant/status')
            .then((res) => {
                if (cancelled) return;
                setEnabled(Boolean(res.data.data.enabled));
                setSuggestions(res.data.data.suggestions ?? []);
            })
            .catch(() => {
                if (!cancelled) setEnabled(false);
            });
        return () => { cancelled = true; };
    }, [canUseAssistant, user?.id]);

    // Pin the newest turn in view as the conversation grows.
    useEffect(() => {
        if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }, [messages, sending]);

    useEffect(() => {
        if (open) inputRef.current?.focus();
    }, [open]);

    useEffect(() => {
        function handleEscape(e) {
            if (e.key === 'Escape') setOpen(false);
        }
        document.addEventListener('keydown', handleEscape);
        return () => document.removeEventListener('keydown', handleEscape);
    }, []);

    async function send(question) {
        const text = question.trim();
        if (!text || sending) return;

        // The turns sent as history are the ones already on screen - the new
        // question travels separately, so it is never duplicated.
        const history = messages.map(({ role, content }) => ({ role, content }));

        setMessages((prev) => [...prev, { role: 'user', content: text }]);
        setDraft('');
        setError('');
        setSending(true);

        try {
            const res = await api.post('/assistant/ask', { message: text, history });
            setMessages((prev) => [...prev, {
                role: 'assistant',
                content: res.data.data.reply,
                // Titles of the knowledge-base passages retrieval pulled in.
                // Shown under the answer so a user can see what it was built
                // from rather than taking it on faith.
                sources: res.data.data.sources ?? [],
            }]);
        } catch (err) {
            // The 503 message is written to be shown as-is; anything else gets a
            // generic line rather than leaking a stack trace into the panel.
            setError(err.response?.data?.message ?? 'Something went wrong. Try again.');
        } finally {
            setSending(false);
        }
    }

    function handleKeyDown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send(draft);
        }
    }

    if (!canUseAssistant || !enabled) return null;

    return (
        <>
            {!open && (
                <button
                    type="button"
                    className="assistant-ask-btn"
                    onClick={() => setOpen(true)}
                    aria-label="Ask the BillboardBD Assistant"
                >
                    <Sparkles size={18} />
                    <span>Ask</span>
                </button>
            )}

            {open && (
                <section className="assistant-panel" aria-label="BillboardBD Assistant">
                    <header className="assistant-panel-header">
                        <span className="assistant-panel-title">
                            <Sparkles size={16} />
                            BillboardBD Assistant
                        </span>
                        <div className="assistant-panel-header-actions">
                            {messages.length > 0 && (
                                <button
                                    type="button"
                                    className="assistant-clear-chat-btn"
                                    onClick={() => { setMessages([]); setError(''); }}
                                >
                                    Clear chat
                                </button>
                            )}
                            <button
                                type="button"
                                className="assistant-close-btn"
                                onClick={() => setOpen(false)}
                                aria-label="Close the assistant"
                            >
                                <X size={16} />
                            </button>
                        </div>
                    </header>

                    <div className="assistant-panel-body" ref={scrollRef}>
                        {messages.length === 0 && (
                            <div className="assistant-empty-state">
                                <p className="assistant-empty-state-lead">
                                    Ask about your {user.role === 'owner' ? 'boards, requests and earnings' : 'bookings, payments, or where to advertise'} — in English or Bangla.
                                </p>
                                {suggestions.map((suggestion) => (
                                    <button
                                        type="button"
                                        className="assistant-suggestion-btn"
                                        key={suggestion}
                                        onClick={() => send(suggestion)}
                                    >
                                        {suggestion}
                                    </button>
                                ))}
                            </div>
                        )}

                        {messages.map((message, i) => (
                            <div
                                className={message.role === 'user' ? 'assistant-user-message' : 'assistant-reply-message'}
                                key={i}
                            >
                                {message.role === 'user' ? message.content : renderMarkdown(message.content)}
                                {message.sources?.length > 0 && (
                                    <p className="assistant-sources">
                                        Based on: {message.sources.join(' · ')}
                                    </p>
                                )}
                            </div>
                        ))}

                        {sending && (
                            <div className="assistant-thinking">
                                <Loader2 size={14} className="assistant-thinking-spinner" />
                                Looking that up…
                            </div>
                        )}

                        {error && <p className="assistant-error">{error}</p>}
                    </div>

                    <footer className="assistant-panel-footer">
                        <textarea
                            className="assistant-input"
                            ref={inputRef}
                            rows={1}
                            value={draft}
                            placeholder="Ask a question…"
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={handleKeyDown}
                            disabled={sending}
                        />
                        <button
                            type="button"
                            className="assistant-send-btn"
                            onClick={() => send(draft)}
                            disabled={sending || draft.trim() === ''}
                            aria-label="Send"
                        >
                            <SendHorizonal size={16} />
                        </button>
                    </footer>
                </section>
            )}
        </>
    );
}
