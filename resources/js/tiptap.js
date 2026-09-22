import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";

export function createTiptapEditor(element, content = "") {
    return new Editor({
        element,

        extensions: [
            StarterKit.configure({
                link: {
                    openOnClick: false,
                    enableClickSelection: true,
                    autolink: true,
                    defaultProtocol: "https",
                },
            }),
        ],

        content,

        editorProps: {
            handleClick(view, pos, event) {
                if (!event.ctrlKey) {
                    return false;
                }

                const { state } = view;
                const $pos = state.doc.resolve(pos);

                const link = $pos
                    .marks()
                    .find((mark) => mark.type.name === "link");

                if (!link) {
                    return false;
                }

                window.open(link.attrs.href, "_blank");

                return true;
            },
        },
    });
}

export function createTiptapComponent(content = "") {
    let editor = null;

    return {
        activeFormats: {},

        init() {
            editor = createTiptapEditor(this.$refs.editor, content);

            editor.on("selectionUpdate", () => {
                this.updateActiveFormats();
            });

            editor.on("transaction", () => {
                this.updateActiveFormats();
            });

            this.updateActiveFormats();
        },

        destroy() {
            if (editor) {
                editor.destroy();
                editor = null;
            }
        },

        updateActiveFormats() {
            if (!editor) {
                return;
            }

            this.activeFormats = {
                paragraph: editor.isActive("paragraph"),
                bold: editor.isActive("bold"),
                italic: editor.isActive("italic"),
                underline: editor.isActive("underline"),
                strike: editor.isActive("strike"),
                link: editor.isActive("link"),
                bulletList: editor.isActive("bulletList"),
                orderedList: editor.isActive("orderedList"),
                heading1: editor.isActive("heading", { level: 1 }),
                heading2: editor.isActive("heading", { level: 2 }),
                heading3: editor.isActive("heading", { level: 3 }),
            };
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

        toggleBulletList() {
            editor?.chain().focus().toggleBulletList().run();
        },

        toggleOrderedList() {
            editor?.chain().focus().toggleOrderedList().run();
        },

        setParagraph() {
            editor?.chain().focus().setParagraph().run();
        },

        setHeading(level) {
            editor?.chain().focus().toggleHeading({ level }).run();
        },

        getContent() {
            return editor?.getHTML() ?? "";
        },

        isActive(format) {
            return editor?.isActive(format) ?? false;
        },

        setBlockType(type) {
            if (!editor) {
                return;
            }

            if (type === "paragraph") {
                editor.chain().focus().setParagraph().run();
                return;
            }

            const level = Number(type.replace("heading", ""));

            editor.chain().focus().setHeading({ level }).run();
        },

        setLink() {
            if (!editor) {
                return;
            }

            const url = window.prompt("Enter URL");

            if (url === null) {
                return;
            }

            if (url === "") {
                editor.chain().focus().unsetLink().run();
                return;
            }

            editor.chain().focus().setLink({ href: url }).run();
        },
    };
}
