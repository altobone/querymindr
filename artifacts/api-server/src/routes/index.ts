import { Router, type IRouter } from "express";
import healthRouter from "./health";
import querymindrRouter from "./querymindr";

const router: IRouter = Router();

router.use(healthRouter);
router.use(querymindrRouter);

export default router;
