import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import TextAlign from "@tiptap/extension-text-align";
import { TextStyle, FontSize } from "@tiptap/extension-text-style";
import Color from "@tiptap/extension-color";
import TaskList from "@tiptap/extension-task-list";
import TaskItem from "@tiptap/extension-task-item";
import { Table } from "@tiptap/extension-table";
import { TableRow } from "@tiptap/extension-table-row";
import { TableCell } from "@tiptap/extension-table-cell";
import { TableHeader } from "@tiptap/extension-table-header";
import Highlight from "@tiptap/extension-highlight";

export function createTiptapEditor(element, content = "", options = {}) {
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
            Highlight,

            TaskList,
            TaskItem.configure({
                nested: true,
            }),

            Table.configure({
                resizable: options.resizable ?? true,
            }),
            TableRow,
            TableHeader,
            TableCell,
        ],

        content,

        editorProps: {
            attributes: {
                role: "textbox",
                "aria-multiline": "true",
                "aria-label": options.label ?? "Note content",
            },

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

export function createTiptapComponent(content = "", options = {}) {
    let editor = null;

    return {
        activeFormats: {},

        init() {
            editor = createTiptapEditor(this.$refs.editor, content, options);

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
                highlight: editor.isActive("highlight"),
                link: editor.isActive("link"),

                bulletList: editor.isActive("bulletList"),
                orderedList: editor.isActive("orderedList"),
                taskList: editor.isActive("taskList"),
                codeBlock: editor.isActive("codeBlock"),
                table: editor.isActive("table"),
                tableWidth: this.getTableWidthPreset(),

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

        toggleTaskList() {
            editor?.chain().focus().toggleTaskList().run();
        },

        toggleCodeBlock() {
            editor?.chain().focus().toggleCodeBlock().run();
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

        toggleHighlight() {
            editor?.chain().focus().toggleHighlight().run();
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


        getTableInfo() {
            if (!editor) {
                return null;
            }

            const { $from } = editor.state.selection;

            for (let depth = $from.depth; depth > 0; depth--) {
                const node = $from.node(depth);

                if (node.type.name === "table") {
                    return { node, pos: $from.before(depth) };
                }
            }

            return null;
        },

        getTableWidth() {
            const info = this.getTableInfo();

            if (!info) {
                return null;
            }

            const firstRow = info.node.firstChild;
            const widths = [];

            if (!firstRow) {
                return null;
            }

            firstRow.forEach((cell) => {
                if (!Array.isArray(cell.attrs.colwidth) || cell.attrs.colwidth.length === 0) {
                    return;
                }

                widths.push(...cell.attrs.colwidth);
            });

            if (widths.length === 0 || widths.some((width) => !Number.isFinite(Number(width)))) {
                return null;
            }

            return widths.reduce((total, width) => total + Number(width), 0);
        },

        getTableWidthPreset() {
            const width = this.getTableWidth();

            if (width === null) {
                return null;
            }

            return [
                ["narrow", 360],
                ["medium", 540],
                ["wide", 720],
            ].find(([, presetWidth]) => Math.abs(width - presetWidth) <= 40)?.[0] ?? null;
        },

        setTableWidth(percent) {
            if (!editor) {
                return;
            }

            const info = this.getTableInfo();

            if (!info) {
                return;
            }

            const table = info.node;
            const firstRow = table.firstChild;

            if (!firstRow) {
                return;
            }

            let columnCount = 0;

            firstRow.forEach((cell) => {
                columnCount += cell.attrs.colspan ?? 1;
            });

            const totalWidth = percent === null ? null : Math.round((720 * percent) / 100);
            const baseWidth = totalWidth === null ? null : Math.floor(totalWidth / columnCount);
            const widths = totalWidth === null
                ? null
                : Array.from({ length: columnCount }, (_, index) => (
                    index === columnCount - 1
                        ? totalWidth - baseWidth * (columnCount - 1)
                        : baseWidth
                ));

            const rows = [];

            table.descendants((node, pos) => {
                if (node.type.name === "tableRow") {
                    rows.push({ cells: [] });

                    return true;
                }

                if (node.type.name === "tableCell" || node.type.name === "tableHeader") {
                    rows.at(-1)?.cells.push({ node, pos });
                }

                return false;
            });

            let transaction = editor.state.tr;

            for (const row of rows) {
                let column = 0;

                for (const cell of row.cells) {
                    const colspan = cell.node.attrs.colspan ?? 1;

                    transaction = transaction.setNodeMarkup(info.pos + 1 + cell.pos, undefined, {
                        ...cell.node.attrs,
                        colwidth: widths === null ? null : widths.slice(column, column + colspan),
                    });

                    column += colspan;
                }
            }

            editor.view.dispatch(transaction);
            editor.commands.focus();
        },

        insertTable() {
            editor
                ?.chain()
                .focus()
                .insertTable({
                    rows: 3,
                    cols: 3,
                    withHeaderRow: true,
                })
                .run();
        },

        addTableRowBefore() {
            editor?.chain().focus().addRowBefore().run();
        },

        addTableRowAfter() {
            editor?.chain().focus().addRowAfter().run();
        },

        deleteTableRow() {
            editor?.chain().focus().deleteRow().run();
        },

        addTableColumnBefore() {
            editor?.chain().focus().addColumnBefore().run();
        },

        addTableColumnAfter() {
            editor?.chain().focus().addColumnAfter().run();
        },

        deleteTableColumn() {
            editor?.chain().focus().deleteColumn().run();
        },

        deleteTable() {
            editor?.chain().focus().deleteTable().run();
        },
    };
}
