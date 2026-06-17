define(['jquery'], function ($) {
    return {
        init: function (courseId, canUpload, backendUrl) {
            // Fix #2: Corrected backend URL — was pointing to localhost:5000 which doesn't exist.
            // This must match the actual server IP and port from docker-compose (5000 external).
            // Fix #8 companion: if you update the URL in Moodle admin settings, update here too.
            const BACKEND_URL = backendUrl;

            // Session Tracking: Generate or retrieve a unique session ID
            let sessionId = sessionStorage.getItem('chatbot_session_id');
            if (!sessionId) {
                sessionId = 'session_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
                sessionStorage.setItem('chatbot_session_id', sessionId);
            }

            courseId = courseId || 0;
            canUpload = canUpload || false;

            if (document.getElementById('chatbot-container')) {
                return;
            }

            const html = `
                <button id="chatbot-open-btn">🤖</button>
                <div id="chatbot-container">
                    <div id="chatbot-header">
                        <span>AI Study Assistant</span>
                        <span id="chatbot-close">×</span>
                    </div>
                    <div id="chatbot-messages"></div>
                    <div id="chatbot-input-container">
                        <textarea id="chatbot-input" placeholder="Ask your question…"></textarea>
                        <button id="chatbot-send">Send</button>
                    </div>
                </div>
            `;

            const div = document.createElement('div');
            div.innerHTML = html;
            document.body.appendChild(div);

            const openBtn = document.getElementById("chatbot-open-btn");
            const chatBox = document.getElementById("chatbot-container");
            const closeBtn = document.getElementById("chatbot-close");
            const sendBtn = document.getElementById("chatbot-send");
            const input = document.getElementById("chatbot-input");
            const messages = document.getElementById("chatbot-messages");
            openBtn.onclick = () => chatBox.style.display = "flex";
            closeBtn.onclick = () => chatBox.style.display = "none";

            function addMessage(text, sender, sources = null) {
                const msg = document.createElement("div");
                msg.className = sender === "user" ? "user-msg" : "bot-msg";
                
                // Strip fallback markdown sources if present to show stylized UI sources
                let cleanText = text;
                const sourceMarkerIndex = cleanText.indexOf("\n\n**Sources:**");
                if (sourceMarkerIndex !== -1) {
                    cleanText = cleanText.substring(0, sourceMarkerIndex);
                }
                
                msg.innerHTML = cleanText.replace(/\n/g, "<br>");
                
                // Extract sources if not passed as array but present in text fallback
                let displaySources = sources;
                if (!displaySources && sourceMarkerIndex !== -1) {
                    const rawSourcesStr = text.substring(sourceMarkerIndex + 14).trim();
                    if (rawSourcesStr) {
                        displaySources = rawSourcesStr.split(",").map(s => s.trim());
                    }
                }

                // Suppress sources display for fallback or greeting responses
                if (cleanText.includes("I don't have enough specific material") || 
                    cleanText.includes("I am sorry, but I cannot engage") || 
                    cleanText.includes("admin side issues")) {
                    displaySources = null;
                }

                if (displaySources && displaySources.length > 0) {
                    const sourcesDiv = document.createElement("div");
                    sourcesDiv.style.marginTop = "8px";
                    sourcesDiv.style.paddingTop = "6px";
                    sourcesDiv.style.borderTop = "1px solid #eee";
                    sourcesDiv.style.fontSize = "11px";
                    sourcesDiv.style.color = "#666";
                    
                    let sourcesHtml = "<strong>Sources:</strong>";
                    displaySources.forEach(src => {
                        sourcesHtml += ` <span style="display:inline-block; background:#e0e0e0; padding:2px 6px; border-radius:10px; margin-left:4px; font-weight:500; font-size:10px; color:#333;">📄 ${src}</span>`;
                    });
                    sourcesDiv.innerHTML = sourcesHtml;
                    msg.appendChild(sourcesDiv);
                }

                messages.appendChild(msg);
                messages.scrollTop = messages.scrollHeight;
            }

            // Send Logic
            const sendMessage = async () => {
                const q = input.value.trim();
                if (!q) return;

                addMessage(q, "user");
                input.value = "";

                const loadingMsg = document.createElement("div");
                loadingMsg.className = "bot-msg";
                loadingMsg.innerText = "Thinking...";
                messages.appendChild(loadingMsg);

                try {
                    // Fix #3b: /chat → /api/chat; Fix #3b: added required index_id field.
                    const res = await fetch(`${BACKEND_URL}/api/chat`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            index_id: courseId, // Fix #3b: backend requires index_id
                            question: q,
                            session_id: sessionId
                        })
                    });

                    if (!res.ok) throw new Error("Server error");

                    const data = await res.json();
                    loadingMsg.remove();
                    // Fix #4: backend returns "answer" field, not "response"
                    addMessage(data.answer || "No response received.", "bot", data.sources);

                } catch (e) {
                    loadingMsg.remove();
                    addMessage(`❌ Error: Backend unreachable on ${BACKEND_URL}`, "bot");
                }
            };

            sendBtn.onclick = sendMessage;
            input.onkeydown = (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            };
        }
    };
});