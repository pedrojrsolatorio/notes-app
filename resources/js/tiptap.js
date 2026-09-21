import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";

export function createTiptapEditor(element, content = "") {
    return new Editor({
        element,

        extensions: [StarterKit],

        content,
    });
}

export function createTiptapComponent(content = "") {
    let editor = null;

    return {
        init() {
            editor = createTiptapEditor(this.$refs.editor, content);
        },

        destroy() {
            if (editor) {
                editor.destroy();
                editor = null;
            }
        },

        toggleBold() {
            editor?.chain().focus().toggleBold().run();
        },

        toggleItalic() {
            editor?.chain().focus().toggleItalic().run();
        },

        toggleUnderline() {
            editor?.chain().focus().toggleUnderline().run();
        },

        toggleStrike() {
            editor?.chain().focus().toggleStrike().run();
        },

        isActive(format) {
            return editor?.isActive(format) ?? false;
        },
    };
}
