
/**
 * Snippet to get GitHub snippets
 *
 * @gravityview
 *
 * GOAL:
 * This is the snippet that powers this snippet directory. The snippet is installed as custom code
 * on a Gravity View and fetches the code from GitHub and displays it in the specified HTML element
 * - in this case the code box. The code box is created mostly through CSS.
 */

addEventListener('DOMContentLoaded', function () {
    const urlElement = document.querySelector('.gv-field-2-4'); // GravityView field ID
    const codeBox = document.getElementById('codeBox');

    if (!urlElement) {
        console.error('GravityView field not found.');
        codeBox.textContent = 'Error: No URL found.';
        return;
    }

    const rawUrl = urlElement.textContent.trim();
    if (!rawUrl || !rawUrl.startsWith('http')) {
        console.error('Invalid URL:', rawUrl);
        codeBox.textContent = 'Error: Invalid URL.';
        return;
    }

    async function fetchCode() {
        try {
            const response = await fetch(rawUrl);
            if (!response.ok) {
                console.error(`HTTP error! Status: ${response.status}`);
            }
            let text = await response.text();
            text = text.replace(/^<\?php\s*/, '');
            codeBox.textContent = text;
        } catch (error) {
            codeBox.textContent = 'Failed to load content.';
            console.error('Error fetching the code:', error);
        }
    }

    fetchCode();

    const title = document.querySelector('.bl-entry-title');
    const snippetTitle = title ? title.textContent.trim() : 'Directory';
    if (typeof gtag === 'function') {
        gtag('event', 'view_snippet', {
            snippet: snippetTitle,
        });
    }
});

function copyCode() {
    const codeElement = document.getElementById('codeBox');
    const textArea = document.createElement('textarea');
    textArea.value = codeElement.textContent;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);

    // Show non-intrusive copy message
    const message = document.getElementById('copyMessage');
    message.style.display = 'block';
    message.style.opacity = '1';

    setTimeout(() => {
        message.style.opacity = '0';
        setTimeout(() => (message.style.display = 'none'), 300);
    }, 1200);

    const title = document.querySelector('.bl-entry-title');
    const snippetTitle = title.textContent.trim();
    if (typeof gtag === 'function') {
        gtag('event', 'copy_snippet', {
            snippet: snippetTitle,
        });
    }
}

function toggleHeight() {
    const codeContainer = document.getElementById('codeContainer');
    if (
        codeContainer.style.maxHeight === '600px' ||
        codeContainer.style.maxHeight === ''
    ) {
        codeContainer.style.maxHeight = 'fit-content'; // Expand
    } else {
        codeContainer.style.maxHeight = '600px'; // Collapse back
    }
    const title = document.querySelector('.bl-entry-title');
    const snippetTitle = title.textContent.trim();
    if (typeof gtag === 'function') {
        gtag('event', 'toggle_snippet_height', {
            snippet: snippetTitle,
        });
    }
}

//Remove excess br tags
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('p').forEach((paragraph) => {
        // Check if the paragraph contains only <script> and <br> elements, ignoring whitespace text nodes
        const hasOnlyScriptAndBr = Array.from(paragraph.childNodes).every(
            (node) =>
                node.nodeName === 'SCRIPT' ||
                node.nodeName === 'BR' ||
                (node.nodeType === Node.TEXT_NODE && !node.nodeValue.trim())
        );

        // If it only contains <script> and <br> elements, remove all <br> elements
        if (hasOnlyScriptAndBr) {
            paragraph.querySelectorAll('br').forEach((br) => br.remove());
        }
    });
});
