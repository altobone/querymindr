import { Router, type IRouter } from "express";
import healthRouter from "./health";
import fileFinderRouter from "./file-finder";

const router: IRouter = Router();

router.use(healthRouter);
router.use(fileFinderRouter);

export default router;
