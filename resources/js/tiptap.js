import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import TextAlign from "@tiptap/extension-text-align";
import { TextStyle, FontSize } from "@tiptap/extension-text-style";
import Color from "@tiptap/extension-color";

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

            TextAlign.configure({
                types: ["heading", "paragraph"],
            }),

            TextStyle,
            FontSize,
            Color,
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
                bold: editor.isActive("bold"),
                italic: editor.isActive("italic"),
                underline: editor.isActive("underline"),
                strike: editor.isActive("strike"),
                link: editor.isActive("link"),

                bulletList: editor.isActive("bulletList"),
                orderedList: editor.isActive("orderedList"),

                paragraph: editor.isActive("paragraph"),
                heading1: editor.isActive("heading", { level: 1 }),
                heading2: editor.isActive("heading", { level: 2 }),
                heading3: editor.isActive("heading", { level: 3 }),

                alignLeft: editor.isActive({ textAlign: "left" }),
                alignCenter: editor.isActive({ textAlign: "center" }),
                alignRight: editor.isActive({ textAlign: "right" }),
                alignJustify: editor.isActive({ textAlign: "justify" }),

                fontSize: this.getEffectiveFontSize(),
                color: this.getEffectiveColor(),
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

        setTextAlign(alignment) {
            if (!editor) {
                return;
            }

            if (
                editor.isActive({ textAlign: alignment }) &&
                alignment !== "left"
            ) {
                editor.chain().focus().setTextAlign("left").run();
                return;
            }

            editor.chain().focus().setTextAlign(alignment).run();
        },

        setFontSize(size) {
            editor?.chain().focus().setFontSize(size).run();
        },

        setColor(color) {
            editor?.chain().focus().setColor(color).run();
        },

        getEffectiveFontSize() {
            if (!editor) {
                return "16px";
            }

            const { state } = editor;
            const { from, to } = state.selection;

            // Text is selected
            if (from !== to) {
                let fontSize = null;
                let hasMixedSizes = false;

                state.doc.nodesBetween(from, to, (node) => {
                    if (!node.isText) {
                        return;
                    }

                    const size =
                        node.marks.find(
                            (mark) => mark.type.name === "textStyle",
                        )?.attrs.fontSize ?? "16px";

                    if (fontSize === null) {
                        fontSize = size;
                    } else if (fontSize !== size) {
                        hasMixedSizes = true;
                    }
                });

                if (hasMixedSizes) {
                    return "mixed";
                }

                return fontSize ?? "16px";
            }

            // Cursor only
            const $pos = state.doc.resolve(from);

            const before = $pos.nodeBefore;
            const after = $pos.nodeAfter;

            const getSize = (node) => {
                if (!node?.isText) {
                    return null;
                }

                return (
                    node.marks.find((mark) => mark.type.name === "textStyle")
                        ?.attrs.fontSize ?? "16px"
                );
            };

            const beforeSize = getSize(before);
            const afterSize = getSize(after);

            if (beforeSize) {
                return beforeSize;
            }

            if (afterSize) {
                return afterSize;
            }

            // Heading defaults
            if (editor.isActive("heading", { level: 1 })) {
                return "32px";
            }

            if (editor.isActive("heading", { level: 2 })) {
                return "24px";
            }

            if (editor.isActive("heading", { level: 3 })) {
                return "20px";
            }

            return "16px";
        },

        getEffectiveColor() {
            if (!editor) {
                return "#000000";
            }

            const { state } = editor;
            const { from, to } = state.selection;

            const getColor = (node) => {
                if (!node?.isText) {
                    return null;
                }

                return (
                    node.marks.find((mark) => mark.type.name === "textStyle")
                        ?.attrs.color ?? "#000000"
                );
            };

            // Text is selected
            if (from !== to) {
                let color = null;
                let hasMixedColors = false;

                state.doc.nodesBetween(from, to, (node) => {
                    if (!node.isText) {
                        return;
                    }

                    const nodeColor = getColor(node);

                    if (color === null) {
                        color = nodeColor;
                    } else if (color !== nodeColor) {
                        hasMixedColors = true;
                    }
                });

                if (hasMixedColors) {
                    return "#000000";
                }

                return color ?? "#000000";
            }

            // Cursor only
            const $pos = state.doc.resolve(from);

            const beforeColor = getColor($pos.nodeBefore);
            const afterColor = getColor($pos.nodeAfter);

            if (beforeColor) {
                return beforeColor;
            }

            if (afterColor) {
                return afterColor;
            }

            return "#000000";
        },
    };
}
