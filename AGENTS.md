# Execution canon (Angel, 2026-11-07)

1. **Velvet CPU work runs only when the drawing engine is Velvet.** On Metal, Vulkan, OpenGL or SDL3, the CPU records commands and nothing else. Every per-pixel computation (filters, maps, distance fields, gradients, images) runs on the GPU. Nothing is built in PHP and uploaded for a GPU engine.
2. **One change per feature, every path at once.** The GPU shaders for all four engines, the extension (ext-fb / ext-rasterize / ext-imgdec), and the plain-PHP fallback all give the same result. There is no "Velvet first, GPU or ext later".
3. **The plain-PHP path is the fallback,** so everything works without the optional extensions. It is never the main design. On Velvet, the per-pixel work lives in the Framebuffer/Rasterize drivers (native + ext), never in VelvetGE or Silk.
4. **Silk describes what to draw.** It holds no per-pixel loops.
5. **Execution gate before "done":** a headless bench of the real gallery at scale 2, on Velvet with the extensions and on Metal. Idle frames settle. A scripted theme switch, hover and click settle within a budget, and input is handled between frames. Every plan states its thread, driver or engine, its cost per frame, and its cache invalidation.

# Knowledge bundle

6. **Read [`.okf/index.md`](.okf/index.md) first** before designing or changing anything. Open only the concepts the change touches. Surface's drawing concepts are in [`../surface/.okf`](../surface/.okf/index.md).
7. **Durable facts go in `.okf`, in the same change.** Update the matching concept, bump its `generated.at`, append `.okf/log.md`. The bundle documents the package, never a session. Validate it with the okf validator `--strict`.
