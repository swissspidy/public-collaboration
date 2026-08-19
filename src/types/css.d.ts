/**
 * Side-effect imports of stylesheets (`import './editor.css'`) have no
 * runtime type of their own — webpack handles them, TypeScript never sees
 * the file. TypeScript 6.0 started flagging those imports (TS2882) unless
 * an ambient module declaration exists, so this is that declaration.
 */
declare module '*.css';
