/**
 * Carousel embed block (sortable images/videos).
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from "@tiptap/core";

import { openMediaBrowserForCarousel } from "../media/browse.js";
import { openCarouselSettings } from "../settings/carousel-settings.js";
import { makeDuplicateButton } from "../ui/duplicate.js";

const CarouselEmbed = Node.create({
  name: "carouselEmbed",
  group: "block",
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      images: {
        default: "",
        parseHTML: (el) => el.getAttribute("images") || "",
      },
      autoplay: {
        default: true,
        parseHTML: (el) => el.getAttribute("autoplay") !== "false",
      },
      duration: {
        default: 5000,
        parseHTML: (el) => parseInt(el.getAttribute("duration") || "5000", 10),
      },
      showArrows: {
        default: true,
        parseHTML: (el) => el.getAttribute("showArrows") !== "false",
      },
      showDots: {
        default: true,
        parseHTML: (el) => el.getAttribute("showDots") !== "false",
      },
      pauseBtn: {
        default: false,
        parseHTML: (el) =>
          el.getAttribute("pauseBtn") === "true" ||
          el.getAttribute("pausebtn") === "true" ||
          el.getAttribute("pause-button") === "true",
      },
      fit: {
        default: "cover",
        parseHTML: (el) => el.getAttribute("fit") || "cover",
      },
      lightbox: {
        default: false,
        parseHTML: (el) => el.getAttribute("lightbox") === "true",
      },
      bg: {
        default: "",
        parseHTML: (el) => el.getAttribute("bg") || "",
      },
      height: {
        default: "200px",
        parseHTML: (el) => el.getAttribute("height") || "200px",
      },
      transition: {
        default: "fade",
        parseHTML: (el) => el.getAttribute("transition") || "fade",
      },
    };
  },

  parseHTML() {
    return [{ tag: "div[data-carousel]" }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return [
      "div",
      mergeAttributes(HTMLAttributes, {
        class: "vl-carousel-embed-block",
        "data-carousel": "1",
        contenteditable: "false",
      }),
    ];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement("div");
      dom.className = "vl-carousel-embed-block";
      dom.contentEditable = "false";
      dom.setAttribute("data-carousel", "1");

      const header = document.createElement("div");
      header.className = "vl-carousel-header";
      header.innerHTML = `<span class="flex-1">🎠 ${tjs("Carousel")}</span>`;

      const settingsBtn = document.createElement("button");
      settingsBtn.type = "button";
      settingsBtn.innerHTML = "⚙";
      settingsBtn.title = tjs("Carousel Settings");
      settingsBtn.dataset.settings = "1";
      settingsBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        openCarouselSettings(editor, node, getPos());
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      const del = document.createElement("button");
      del.type = "button";
      del.className = "vl-form-embed-delete";
      del.textContent = "✕";
      del.addEventListener("click", () => {
        const pos = getPos();
        editor
          .chain()
          .focus()
          .deleteRange({ from: pos, to: pos + node.nodeSize })
          .run();
      });
      header.appendChild(del);

      const body = document.createElement("div");
      body.className = "vl-carousel-body";

      function renderImages() {
        body.innerHTML = "";
        const urls = (node.attrs.images || "").split(",").filter(Boolean);
        if (urls.length === 0) {
          const empty = document.createElement("div");
          empty.className = "vl-carousel-empty";
          empty.textContent = tjs("No images selected. Click to add.");
          empty.addEventListener("click", () => addImages());
          body.appendChild(empty);
        } else {
          const grid = document.createElement("div");
          grid.className = "vl-carousel-grid sortable-grid";
          urls.forEach((url, idx) => {
            const item = document.createElement("div");
            item.className = "vl-carousel-item";
            item.setAttribute("data-index", idx);

            const isVid =
              url.match(/\.(mp4|webm|ogg|mov|m4v)($|\?)/i) ||
              url.includes("youtube.com") ||
              url.includes("youtu.be") ||
              url.includes("vimeo.com");
            const mediaHtml = isVid
              ? `<div class="w-full h-full flex items-center justify-center vl-bg-soft text-2xl">🎬</div>`
              : `<img src="${url}">`;

            item.innerHTML = `<span class="drag-handle" title="${tjs("Drag to reorder")}">⠿</span>${mediaHtml}<button type="button" class="vl-carousel-remove-img" title="${tjs("Remove")}">✕</button>`;
            item.querySelector("button").addEventListener("click", (e) => {
              e.stopPropagation();
              const newUrls = urls.filter((_, i) => i !== idx);
              updateNode({ images: newUrls.join(",") });
            });
            grid.appendChild(item);
          });
          const addBtn = document.createElement("button");
          addBtn.type = "button";
          addBtn.className = "vl-carousel-add-btn";
          addBtn.innerHTML = "<span>+</span>";
          addBtn.addEventListener("click", () => addImages());
          grid.appendChild(addBtn);
          body.appendChild(grid);

          // Reorder with SortableJS
          if (typeof Sortable !== "undefined") {
            Sortable.create(grid, {
              animation: 150,
              handle: ".drag-handle",
              ghostClass: "vl-carousel-ghost",
              onEnd() {
                const items = [...grid.querySelectorAll(".vl-carousel-item")];
                const sorted = items.map(
                  (el) => urls[parseInt(el.dataset.index)],
                );
                updateNode({ images: sorted.join(",") });
              },
            });
          }
        }
      }

      function addImages() {
        const currentUrls = (node.attrs.images || "")
          .split(",")
          .map((u) => u.trim())
          .filter(Boolean);
        openMediaBrowserForCarousel(
          editor,
          (selectedUrls) => {
            updateNode({ images: selectedUrls.join(",") });
          },
          currentUrls,
        );
      }

      function updateNode(attrs) {
        const pos = getPos();
        editor
          .chain()
          .focus()
          .command(({ tr }) => {
            tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...attrs });
            return true;
          })
          .run();
      }

      renderImages();

      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== "carouselEmbed") return false;
          node = updatedNode;
          renderImages();
          return true;
        },
      };
    };
  },
});

export { CarouselEmbed };
